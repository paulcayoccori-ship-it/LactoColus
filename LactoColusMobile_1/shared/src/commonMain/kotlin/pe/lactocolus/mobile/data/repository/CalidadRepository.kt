package pe.lactocolus.mobile.data.repository

import app.cash.sqldelight.coroutines.asFlow
import app.cash.sqldelight.coroutines.mapToList
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlinx.datetime.Instant
import kotlinx.datetime.TimeZone
import kotlinx.datetime.atStartOfDayIn
import kotlinx.datetime.toLocalDateTime
import pe.lactocolus.mobile.core.common.AppError
import pe.lactocolus.mobile.core.common.DispatcherProvider
import pe.lactocolus.mobile.core.common.Reloj
import pe.lactocolus.mobile.core.common.Result
import pe.lactocolus.mobile.core.common.fechaHoraIso
import pe.lactocolus.mobile.core.common.fechaOperativaIso
import pe.lactocolus.mobile.core.common.randomUuid
import pe.lactocolus.mobile.core.ui.Formato
import pe.lactocolus.mobile.data.local.toDomain
import pe.lactocolus.mobile.data.local.toLong
import pe.lactocolus.mobile.data.remote.LactoColusApi
import pe.lactocolus.mobile.data.sync.EstadoApp
import pe.lactocolus.mobile.db.LactoColusDb
import pe.lactocolus.mobile.domain.model.Analisis
import pe.lactocolus.mobile.domain.model.EntregaCalidad
import pe.lactocolus.mobile.domain.model.JornadaCalidad
import pe.lactocolus.mobile.domain.model.ParametroAnalisis
import pe.lactocolus.mobile.domain.model.ResultadoAnalisis
import pe.lactocolus.mobile.domain.model.TipoEntidadSync
import pe.lactocolus.mobile.domain.repository.AnalisisRepository
import pe.lactocolus.mobile.domain.repository.CalidadJornadaRepository
import pe.lactocolus.mobile.domain.repository.ResumenCalidad
import pe.lactocolus.mobile.domain.repository.SyncRepository

class AnalisisRepositoryImpl(
    private val db: LactoColusDb,
    private val dispatchers: DispatcherProvider,
    private val reloj: Reloj,
    private val cola: ColaSyncWriter,
    private val sync: SyncRepository,
    private val appScope: CoroutineScope,
) : AnalisisRepository {

    private val q get() = db.calidadQueries

    override fun analisis(): Flow<List<Analisis>> =
        q.analisis().asFlow().mapToList(dispatchers.io).map { list -> list.map { it.conParametros() } }

    override fun analisisDeProductor(productorId: String): Flow<List<Analisis>> =
        q.analisisDeProductor(productorId).asFlow().mapToList(dispatchers.io).map { list -> list.map { it.conParametros() } }

    override suspend fun analisisPorId(idLocal: String): Analisis? = withContext(dispatchers.io) {
        q.analisisPorId(idLocal).executeAsOneOrNull()?.conParametros()
    }

    override fun resumenHoy(): Flow<ResumenCalidad> {
        val (desde, hasta) = rangoHoy()
        return combine(
            q.analisisDeHoy(desde, hasta).asFlow().mapToList(dispatchers.io),
            q.contarObservados().asFlow().mapToList(dispatchers.io),
            q.contarPendientesRevision().asFlow().mapToList(dispatchers.io),
            q.contarAnalisisSinSync().asFlow().mapToList(dispatchers.io),
        ) { hoy, observados, pendientes, sinSync ->
            ResumenCalidad(
                hoy = hoy.size,
                observados = observados.firstOrNull()?.toInt() ?: 0,
                pendientes = pendientes.firstOrNull()?.toInt() ?: 0,
                sinSincronizar = sinSync.firstOrNull()?.toInt() ?: 0,
            )
        }
    }

    override suspend fun registrarAnalisis(
        productorId: String,
        fechaMillis: Long,
        equipo: String?,
        parametros: List<ParametroAnalisis>,
        aguaAnadida: Double,
        observaciones: String?,
        entregaId: Long?,
    ): Result<Analisis> = withContext(dispatchers.io) {
        val id = randomUuid()
        // Si el equipo reporta agua añadida > 0, la muestra queda observada (spec §5).
        val resultado = if (aguaAnadida > 0.0) ResultadoAnalisis.OBSERVADO else ResultadoAnalisis.PENDIENTE_REVISION
        db.transaction {
            q.insertAnalisis(
                id_local = id,
                id_remoto = null,
                uuid_publico = null,
                productor_id = productorId,
                fecha = fechaMillis,
                equipo = equipo,
                fuente = "manual",
                resultado = resultado.name,
                agua_anadida = aguaAnadida,
                observaciones = observaciones,
                estado_sync = "PENDIENTE",
                sync_mensaje = null,
                sync_intentos = 0L,
                clave_idempotencia = id,
            )
            parametros.forEach { p ->
                q.insertParametro(
                    id_local = randomUuid(),
                    analisis_id = id,
                    clave = p.clave,
                    valor = p.valor,
                    unidad = p.unidad,
                    dentro_de_rango = p.dentroDeRango?.let { if (it) 1L else 0L },
                    limite_min = p.limiteMin,
                    limite_max = p.limiteMax,
                )
            }
        }
        // Named per-field payload (not a joined blob) so SyncEngine can read each backend-required
        // parameter directly and detect a missing one instead of sending it as a fabricated zero.
        val porClave = parametros.associateBy { it.clave }
        fun valorTexto(clave: String): String? = porClave[clave]?.valor?.let { Formato.decimal(it, 4).replace(',', '.') }
        val payload = jsonPayload(
            "uuid_externo" to id,
            "productor_id" to productorId,
            "muestra_at" to fechaHoraIso(fechaMillis),
            "equipo" to equipo,
            "fuente" to "manual",
            "grasa" to valorTexto("grasa"),
            "proteina" to valorTexto("proteina"),
            "lactosa" to valorTexto("lactosa"),
            "densidad_medida" to valorTexto("densidad_medida"),
            "temperatura" to valorTexto("temperatura"),
            "solidos_no_grasos" to valorTexto("solidos_no_grasos"),
            "ph" to valorTexto("ph"),
            "acidez" to valorTexto("acidez"),
            "agua_anadida" to Formato.decimal(aguaAnadida, 4).replace(',', '.'),
            "densidad_corregida" to valorTexto("densidad_corregida"),
            "solidos_totales" to valorTexto("solidos_totales"),
            "entrega_id" to entregaId?.toString(),
            "observaciones" to observaciones,
        )
        cola.encolar(TipoEntidadSync.ANALISIS, id, id, payload)
        // Sube de inmediato en segundo plano (spec: el personal de calidad no pulsa nada). No se
        // espera aquí — appScope, no dispatchers.io de este withContext — para no retrasar el
        // retorno; si falla o no hay red, el registro ya quedó en cola_sync y se reintenta solo.
        appScope.launch { sync.sincronizarTodo() }
        Result.Success(q.analisisPorId(id).executeAsOne().conParametros())
    }

    private fun pe.lactocolus.mobile.db.Analisis.conParametros(): Analisis {
        val params = q.parametrosDeAnalisis(id_local).executeAsList().map { it.toDomain() }
        return toDomain(params)
    }

    private fun rangoHoy(): Pair<Long, Long> {
        val zona = TimeZone.of("America/Lima")
        val hoy = reloj.ahora().toLocalDateTime(zona).date
        val inicio = hoy.atStartOfDayIn(zona).toEpochMilliseconds()
        return inicio to (inicio + 24L * 60 * 60 * 1000)
    }
}

class CalidadJornadaRepositoryImpl(
    private val db: LactoColusDb,
    private val dispatchers: DispatcherProvider,
    private val reloj: Reloj,
    private val api: LactoColusApi,
    private val estadoApp: EstadoApp,
) : CalidadJornadaRepository {

    private val q get() = db.calidadQueries

    override fun jornadasDeHoy(): Flow<List<JornadaCalidad>> =
        q.jornadasDeHoy(fechaOperativaIso(reloj.ahoraMillis())).asFlow().mapToList(dispatchers.io).map { list -> list.map { it.toDomain() } }

    override fun entregasDeJornada(jornadaIdRemoto: Long): Flow<List<EntregaCalidad>> =
        q.entregasDeJornadaCalidad(jornadaIdRemoto).asFlow().mapToList(dispatchers.io).map { list -> list.map { it.toDomain() } }

    override suspend fun descargarJornadas(): Result<Unit> = withContext(dispatchers.io) {
        try {
            val hoy = fechaOperativaIso(reloj.ahoraMillis())
            // GET /api/v1/calidad/jornadas no pagina (el backend usa Collection::get(), no
            // paginate()) — una sola llamada trae todo. Igual se escribe todo en UNA transacción
            // (no una por fila) para no parpadear el Flow que lee jornada_calidad/entrega_calidad.
            val todas = api.listarJornadasCalidad(fecha = hoy, estado = null, recolectorId = null)

            db.transaction {
                todas.forEach { jornada ->
                    q.insertJornadaCalidad(
                        id_remoto = jornada.id,
                        uuid_publico = jornada.uuidPublico,
                        ruta_id = jornada.rutaId,
                        ruta_codigo = jornada.rutaCodigo,
                        ruta = jornada.ruta,
                        recolector_id = jornada.recolectorId,
                        recolector = jornada.recolector,
                        fecha_operativa = jornada.fechaOperativa,
                        turno = jornada.turno,
                        estado = jornada.estado,
                        litros = jornada.litros.toDoubleOrNull() ?: 0.0,
                        cantidad_entregas = jornada.cantidadEntregas.toLong(),
                    )
                    jornada.entregas.forEach { entrega ->
                        q.insertEntregaCalidad(
                            id_remoto = entrega.id,
                            jornada_id_remoto = jornada.id,
                            productor_id = entrega.productorId,
                            productor_codigo = entrega.productorCodigo,
                            productor_nombres = entrega.productorNombres,
                            productor_apellidos = entrega.productorApellidos,
                            litros = entrega.litros.toDoubleOrNull() ?: 0.0,
                            recolectada_at = runCatching { Instant.parse(entrega.recolectadaAt).toEpochMilliseconds() }.getOrDefault(reloj.ahoraMillis()),
                            tiene_analisis = entrega.tieneAnalisis.toLong(),
                        )
                    }
                }
            }
            estadoApp.sincronizacionTerminada(reloj.ahoraMillis())
            Result.Success(Unit)
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            Result.Failure(AppError.Sincronizacion("No se pudo actualizar las jornadas de calidad."))
        }
    }
}
