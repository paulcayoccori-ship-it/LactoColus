package pe.lactocolus.mobile.data.sync

import app.cash.sqldelight.coroutines.asFlow
import app.cash.sqldelight.coroutines.mapToList
import app.cash.sqldelight.coroutines.mapToOne
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonPrimitive
import pe.lactocolus.mobile.core.common.AppError
import pe.lactocolus.mobile.core.common.DispatcherProvider
import pe.lactocolus.mobile.core.common.Reloj
import pe.lactocolus.mobile.core.common.Result
import pe.lactocolus.mobile.core.ui.SyncState
import pe.lactocolus.mobile.data.local.toDomain
import pe.lactocolus.mobile.data.remote.AbrirJornadaRequest
import pe.lactocolus.mobile.data.remote.EntregaSyncDto
import pe.lactocolus.mobile.data.remote.AnalisisSyncDto
import pe.lactocolus.mobile.data.remote.ErrorRemotoException
import pe.lactocolus.mobile.data.remote.ItemResultado
import pe.lactocolus.mobile.data.remote.LactoColusApi
import pe.lactocolus.mobile.data.remote.SincronizacionResponse
import pe.lactocolus.mobile.data.remote.SincronizarAnalisisRequest
import pe.lactocolus.mobile.data.remote.SincronizarEntregasRequest
import pe.lactocolus.mobile.data.remote.TrasladoRequest
import pe.lactocolus.mobile.db.Cola_sync
import pe.lactocolus.mobile.db.LactoColusDb
import pe.lactocolus.mobile.domain.model.ColaSyncItem
import pe.lactocolus.mobile.domain.model.TipoEntidadSync
import pe.lactocolus.mobile.domain.repository.SyncRepository

/**
 * Deferred sync engine (spec §3). Processes `cola_sync` in creation order with exponential
 * backoff between attempts, exposes "Sincronizar todo" and per-item retry, and maps each
 * server outcome to an [pe.lactocolus.mobile.domain.model.EstadoSync]:
 * CREADO / REPETIDO (ya existía en la planta) / RECHAZADO (con el mensaje del servidor).
 * The underlying entity row is updated to match so screens reflect the result.
 */
class SyncEngine(
    private val db: LactoColusDb,
    private val api: LactoColusApi,
    private val dispatchers: DispatcherProvider,
    private val reloj: Reloj,
    private val estadoApp: EstadoApp,
) : SyncRepository {

    private val json = Json { ignoreUnknownKeys = true }
    private val q get() = db.syncQueries

    /** The nine fields `POST /api/v1/calidad/sincronizar` requires on every item. */
    private val CAMPOS_ANALISIS_REQUERIDOS = listOf(
        "grasa", "proteina", "lactosa", "densidad_medida", "temperatura",
        "solidos_no_grasos", "ph", "acidez", "agua_anadida",
    )

    private val _ultima = MutableStateFlow<Long?>(null)
    override val ultimaSincronizacionMillis: StateFlow<Long?> = _ultima

    override fun cola(): Flow<List<ColaSyncItem>> =
        q.todos().asFlow().mapToList(dispatchers.io).map { list -> list.map { it.toDomain() } }

    override fun pendientes(): Flow<Int> =
        q.contarPendientes().asFlow().mapToOne(dispatchers.io).map { it.toInt() }

    override suspend fun sincronizarTodo(): Result<Unit> = withContext(dispatchers.io) {
        val items = q.pendientes().executeAsList()
        if (items.isEmpty()) {
            estadoApp.estadoSync(SyncState.Inactivo)
            return@withContext Result.Success(Unit)
        }
        estadoApp.estadoSync(SyncState.Sincronizando)
        var huboError = false
        items.forEachIndexed { indice, item ->
            if (indice > 0) delay(backoff(item.intentos.toInt()))
            val ok = procesar(item)
            if (!ok) huboError = true
        }
        val ahora = reloj.ahoraMillis()
        _ultima.value = ahora
        return@withContext if (huboError) {
            estadoApp.estadoSync(SyncState.ErrorSincronizacion("Algunos registros fueron rechazados"))
            Result.Failure(AppError.Sincronizacion("Algunos registros fueron rechazados"))
        } else {
            estadoApp.sincronizacionTerminada(ahora)
            Result.Success(Unit)
        }
    }

    override suspend fun reintentar(idLocal: String): Result<Unit> = withContext(dispatchers.io) {
        q.reintentar(reloj.ahoraMillis(), idLocal)
        val item = q.porId(idLocal).executeAsOneOrNull() ?: return@withContext Result.Failure(AppError.NoEncontrado)
        val ok = procesar(item)
        if (ok) Result.Success(Unit) else Result.Failure(AppError.Sincronizacion("El servidor rechazó el registro"))
    }

    /** 1s, 2s, 4s, 8s… capped at 30s. */
    private fun backoff(intentos: Int): Long =
        minOf(30_000L, 1_000L * (1L shl minOf(intentos, 5)))

    private suspend fun procesar(item: Cola_sync): Boolean {
        q.marcarEnviando(reloj.ahoraMillis(), item.id_local)
        return try {
            val respuesta = enviar(item)
            aplicarResultado(item, respuesta)
        } catch (e: Throwable) {
            q.marcarResultado("RECHAZADO", e.message ?: "Error de red", reloj.ahoraMillis(), item.id_local)
            actualizarEntidad(item, "RECHAZADO", e.message)
            false
        }
    }

    private suspend fun enviar(item: Cola_sync): SincronizacionResponse = when (item.tipoEntidad()) {
        TipoEntidadSync.ENTREGA -> {
            val dto = entregaDto(item)
                ?: throw IllegalStateException("La jornada de esta entrega aún no se sincronizó; se reintentará.")
            api.sincronizarEntregas(SincronizarEntregasRequest(listOf(dto)))
        }
        TipoEntidadSync.ANALISIS -> {
            val dto = analisisDto(item)
                ?: throw IllegalStateException("El productor de este análisis aún no se sincronizó; se reintentará.")
            api.sincronizarAnalisis(SincronizarAnalisisRequest(listOf(dto)))
        }
        TipoEntidadSync.JORNADA -> enviarJornada(item)
        TipoEntidadSync.TRASLADO -> {
            api.solicitarTraslado(
                TrasladoRequest(
                    uuid = item.clave_idempotencia,
                    rutaSolicitadaId = campo(item, "ruta_solicitada_id"),
                    fechaEfectiva = campo(item, "fecha_efectiva"),
                    motivo = campo(item, "motivo"),
                ),
            )
            SincronizacionResponse(creados = listOf(ItemResultado(0)))
        }
        TipoEntidadSync.LECTURA_COMUNICADO -> {
            api.marcarLectura(item.clave_idempotencia)
            SincronizacionResponse(creados = listOf(ItemResultado(0)))
        }
    }

    /**
     * Abre la jornada encolada offline. Si el backend responde 422 (índice único
     * ruta+fecha+turno — alguien más ya la abrió), la adopta en vez de fallar: mismo criterio
     * que [pe.lactocolus.mobile.data.repository.JornadaRepositoryImpl.iniciarJornadaHoy]. En
     * ambos casos exitosos escribe `id_remoto`/`uuid_publico` localmente aquí mismo — por eso
     * [actualizarEntidad] ignora el caso JORNADA en éxito, para no pisar este resultado.
     */
    private suspend fun enviarJornada(item: Cola_sync): SincronizacionResponse {
        val rutaId = campo(item, "ruta_id")
        val fecha = campo(item, "fecha_operativa")
        val turno = campo(item, "turno")
        try {
            val remota = api.abrirJornada(AbrirJornadaRequest(rutaId = rutaId, fechaOperativa = fecha, turno = turno))
            db.acopioQueries.marcarJornadaSync("CREADO", remota.id, remota.uuidPublico, item.entidad_id)
            return SincronizacionResponse(creados = listOf(ItemResultado(0)))
        } catch (e: ErrorRemotoException) {
            if (e.status != 422) throw e
            val idRutaRemota = rutaId.toLongOrNull() ?: throw e
            val pagina = api.listarJornadas(1, 1, estado = "abierta", rutaId = idRutaRemota, desde = fecha, hasta = fecha)
            val existente = pagina.data.firstOrNull() ?: throw e
            db.acopioQueries.marcarJornadaSync("CREADO", existente.id, existente.uuidPublico, item.entidad_id)
            return SincronizacionResponse(creados = listOf(ItemResultado(0)))
        }
    }

    private fun aplicarResultado(item: Cola_sync, r: SincronizacionResponse): Boolean {
        val ahora = reloj.ahoraMillis()
        return when {
            r.rechazados.isNotEmpty() -> {
                val msg = r.rechazados.first().errores.values.flatten().joinToString(", ").ifEmpty { "Rechazado por el servidor" }
                q.marcarResultado("RECHAZADO", msg, ahora, item.id_local)
                actualizarEntidad(item, "RECHAZADO", msg)
                false
            }
            r.repetidos.isNotEmpty() -> {
                q.marcarResultado("REPETIDO", "Ya existía en la planta", ahora, item.id_local)
                actualizarEntidad(item, "REPETIDO", "Ya existía en la planta")
                true
            }
            else -> {
                q.marcarResultado("CREADO", null, ahora, item.id_local)
                actualizarEntidad(item, "CREADO", null)
                true
            }
        }
    }

    /**
     * JORNADA en éxito (CREADO/REPETIDO) no hace nada aquí: [enviarJornada] ya escribió
     * `id_remoto`/`uuid_publico` con el dato real devuelto por el backend, y sobreescribirlo
     * de nuevo con `null` es exactamente el bug que descartaba el id remoto de una jornada
     * creada offline, dejando a sus entregas sin `jornada_id` válido para sincronizar.
     */
    private fun actualizarEntidad(item: Cola_sync, estado: String, mensaje: String?) {
        when (item.tipoEntidad()) {
            TipoEntidadSync.ENTREGA ->
                db.acopioQueries.marcarEntregaSync(estado, mensaje, null, item.entidad_id)
            TipoEntidadSync.ANALISIS ->
                db.calidadQueries.marcarAnalisisSync(estado, mensaje, null, null, item.entidad_id)
            TipoEntidadSync.JORNADA ->
                if (estado == "RECHAZADO") db.acopioQueries.marcarJornadaSync(estado, null, null, item.entidad_id)
            TipoEntidadSync.TRASLADO ->
                db.productorQueries.marcarTrasladoSync(estado, mensaje, null, item.entidad_id)
            TipoEntidadSync.LECTURA_COMUNICADO -> Unit
        }
    }

    /**
     * Resuelve jornada_id/productor_id locales a sus ids remotos justo antes de enviar — la
     * cola guarda los locales porque son los únicos que existen al registrar la entrega. Si la
     * jornada de esta entrega todavía no tiene `id_remoto` (aún no terminó de sincronizarse),
     * devuelve `null`: el ítem se deja para el siguiente intento en vez de mandarse mal formado.
     */
    private fun entregaDto(item: Cola_sync): EntregaSyncDto? {
        val jornadaLocal = campo(item, "jornada_id")
        val productorLocal = campo(item, "productor_id")
        val idRemotoJornada = db.acopioQueries.jornadaPorId(jornadaLocal).executeAsOneOrNull()?.id_remoto ?: return null
        val idRemotoProductor = db.acopioQueries.productorPorId(productorLocal).executeAsOneOrNull()?.id_remoto ?: return null
        return EntregaSyncDto(
            uuidCliente = item.clave_idempotencia,
            jornadaId = idRemotoJornada,
            productorId = idRemotoProductor,
            litros = campo(item, "litros").toDoubleOrNull() ?: 0.0,
            recolectadaAt = campo(item, "recolectada_at"),
            observacion = campoOpcional(item, "observacion") ?: "",
        )
    }

    /**
     * Resuelve productor_id local a su id remoto justo antes de enviar (mismo criterio que
     * [entregaDto]): si el productor aún no tiene `id_remoto`, devuelve `null` y el ítem se deja
     * `PENDIENTE` para el siguiente intento en vez de mandarse mal formado. Si falta alguno de
     * los nueve parámetros obligatorios del backend en el payload local (el formulario no obliga
     * a llenarlos todos hoy — ver [pe.lactocolus.mobile.feature.calidad.NuevoAnalisisScreen]),
     * rechaza el envío con un mensaje real en vez de inventar un cero.
     */
    private fun analisisDto(item: Cola_sync): AnalisisSyncDto? {
        val productorLocal = campo(item, "productor_id")
        val idRemotoProductor = db.acopioQueries.productorPorId(productorLocal).executeAsOneOrNull()?.id_remoto ?: return null
        val faltantes = CAMPOS_ANALISIS_REQUERIDOS.filter { campoOpcional(item, it) == null }
        if (faltantes.isNotEmpty()) {
            throw IllegalStateException("Faltan parámetros obligatorios: ${faltantes.joinToString(", ")}")
        }
        return AnalisisSyncDto(
            uuidExterno = item.clave_idempotencia,
            productorId = idRemotoProductor,
            muestraAt = campo(item, "muestra_at"),
            fuente = campoOpcional(item, "fuente") ?: "manual",
            grasa = campo(item, "grasa").toDouble(),
            proteina = campo(item, "proteina").toDouble(),
            lactosa = campo(item, "lactosa").toDouble(),
            densidadMedida = campo(item, "densidad_medida").toDouble(),
            temperatura = campo(item, "temperatura").toDouble(),
            solidosNoGrasos = campo(item, "solidos_no_grasos").toDouble(),
            ph = campo(item, "ph").toDouble(),
            acidez = campo(item, "acidez").toDouble(),
            aguaAnadida = campo(item, "agua_anadida").toDouble(),
            densidadCorregida = campoOpcional(item, "densidad_corregida")?.toDoubleOrNull(),
            solidosTotales = campoOpcional(item, "solidos_totales")?.toDoubleOrNull(),
            equipo = campoOpcional(item, "equipo"),
            observaciones = campoOpcional(item, "observaciones"),
        )
    }

    private fun campo(item: Cola_sync, clave: String): String = campoOpcional(item, clave).orEmpty()

    private fun campoOpcional(item: Cola_sync, clave: String): String? = runCatching {
        json.parseToJsonElement(item.payload).let { el ->
            (el as? kotlinx.serialization.json.JsonObject)?.get(clave)?.jsonPrimitive?.contentOrNull
        }
    }.getOrNull()

    private fun Cola_sync.tipoEntidad(): TipoEntidadSync =
        runCatching { TipoEntidadSync.valueOf(tipo_entidad) }.getOrDefault(TipoEntidadSync.ENTREGA)
}
