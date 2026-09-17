package pe.lactocolus.mobile.data.repository

import app.cash.sqldelight.coroutines.asFlow
import app.cash.sqldelight.coroutines.mapToList
import app.cash.sqldelight.coroutines.mapToOneOrNull
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlinx.datetime.Instant
import pe.lactocolus.mobile.core.common.AppError
import pe.lactocolus.mobile.core.common.DispatcherProvider
import pe.lactocolus.mobile.core.common.Reloj
import pe.lactocolus.mobile.core.common.Result
import pe.lactocolus.mobile.core.common.fechaHoraIso
import pe.lactocolus.mobile.core.common.randomUuid
import pe.lactocolus.mobile.core.ui.Formato
import pe.lactocolus.mobile.data.local.toDomain
import pe.lactocolus.mobile.data.remote.AbrirJornadaRequest
import pe.lactocolus.mobile.data.remote.ErrorRemotoException
import pe.lactocolus.mobile.data.remote.JornadaCerradaRemota
import pe.lactocolus.mobile.data.remote.JornadaListadoRemota
import pe.lactocolus.mobile.data.remote.LactoColusApi
import pe.lactocolus.mobile.data.remote.ProductorRemoto
import pe.lactocolus.mobile.data.sync.EstadoApp
import pe.lactocolus.mobile.db.LactoColusDb
import pe.lactocolus.mobile.domain.model.Entrega
import pe.lactocolus.mobile.domain.model.Jornada
import pe.lactocolus.mobile.domain.model.Productor
import pe.lactocolus.mobile.domain.model.Ruta
import pe.lactocolus.mobile.domain.model.TipoEntidadSync
import pe.lactocolus.mobile.domain.repository.EntregaRepository
import pe.lactocolus.mobile.domain.repository.JornadaRepository
import pe.lactocolus.mobile.domain.repository.RutaRepository
import pe.lactocolus.mobile.domain.repository.SyncRepository
import pe.lactocolus.mobile.db.Jornada as DbJornada

class RutaRepositoryImpl(
    private val db: LactoColusDb,
    private val dispatchers: DispatcherProvider,
    private val api: LactoColusApi,
    private val reloj: Reloj,
    private val estadoApp: EstadoApp,
) : RutaRepository {

    private val q get() = db.acopioQueries

    override fun rutas(): Flow<List<Ruta>> =
        q.rutas().asFlow().mapToList(dispatchers.io).map { list -> list.map { it.toDomain() } }

    override suspend fun ruta(idLocal: String): Ruta? = withContext(dispatchers.io) {
        q.rutaPorId(idLocal).executeAsOneOrNull()?.toDomain()
    }

    override fun productoresDeRuta(rutaId: String): Flow<List<Productor>> =
        q.productoresDeRuta(rutaId).asFlow().mapToList(dispatchers.io).map { list -> list.map { it.toDomain() } }

    override fun buscarProductores(query: String): Flow<List<Productor>> =
        q.buscarProductores(query).asFlow().mapToList(dispatchers.io).map { list -> list.map { it.toDomain() } }

    override suspend fun productor(idLocal: String): Productor? = withContext(dispatchers.io) {
        q.productorPorId(idLocal).executeAsOneOrNull()?.toDomain()
    }

    override suspend fun productorPorIdRemoto(idRemoto: Long): Productor? = withContext(dispatchers.io) {
        q.productorPorIdRemoto(idRemoto).executeAsOneOrNull()?.toDomain()
    }

    override suspend fun descargarProductores(): Result<Unit> = withContext(dispatchers.io) {
        try {
            // Igual que descargarJornadas(): se piden TODAS las páginas primero (solo red) y se
            // escribe en UNA transacción al final — un commit por página reemitiría los Flow que
            // leen `productor` (búsqueda, listado de ruta) en cada página, causando parpadeo
            // mientras dura la descarga.
            val todos = mutableListOf<ProductorRemoto>()
            var pagina = 1
            var ultimaPagina = 1
            do {
                val respuesta = api.listarProductores(pagina, PRODUCTORES_POR_PAGINA)
                todos += respuesta.data
                ultimaPagina = respuesta.meta.ultimaPagina.coerceAtLeast(1)
                pagina++
            } while (pagina <= ultimaPagina)

            db.transaction {
                todos.forEach { remoto ->
                    val activo = if (remoto.estado) 1L else 0L
                    val existente = q.productorPorIdRemoto(remoto.id).executeAsOneOrNull()
                    if (existente != null) {
                        q.actualizarProductorRemoto(
                            codigo = remoto.codigo,
                            nombres = remoto.nombres,
                            apellidos = remoto.apellidos,
                            activo = activo,
                            id_local = existente.id_local,
                        )
                    } else {
                        // ruta_id/orden_visita/promedio_litros: el endpoint no los devuelve,
                        // se dejan en su default (null / 0 / 0.0) — no se inventan.
                        q.insertProductor(
                            id_local = randomUuid(),
                            id_remoto = remoto.id,
                            codigo = remoto.codigo,
                            nombres = remoto.nombres,
                            apellidos = remoto.apellidos,
                            ruta_id = null,
                            orden_visita = 0,
                            activo = activo,
                            promedio_litros = 0.0,
                        )
                    }
                }
            }
            Result.Success(Unit)
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            Result.Failure(AppError.Sincronizacion("No se pudo actualizar el padrón de productores."))
        }
    }

    override suspend fun descargarRutas(): Result<Unit> = withContext(dispatchers.io) {
        try {
            val rutasRemotas = api.listarRutas()
            db.transaction {
                rutasRemotas.forEach { remoto ->
                    val existente = q.rutaPorIdRemoto(remoto.id).executeAsOneOrNull()
                    val idLocal = existente?.id_local ?: randomUuid()
                    q.insertRuta(
                        id_local = idLocal,
                        id_remoto = remoto.id,
                        codigo = remoto.codigo,
                        nombre = remoto.nombre,
                        turno = TURNO_UNICO,
                        vuelta = 1L,
                        estado = "activa",
                        total_productores = remoto.productores.size.toLong(),
                    )
                    remoto.productores.forEach { p ->
                        val prodExistente = q.productorPorIdRemoto(p.id).executeAsOneOrNull()
                        if (prodExistente != null) {
                            q.asignarProductorARuta(ruta_id = idLocal, orden_visita = p.orden.toLong(), id_local = prodExistente.id_local)
                        } else {
                            q.insertProductor(
                                id_local = randomUuid(),
                                id_remoto = p.id,
                                codigo = p.codigo,
                                nombres = p.nombres,
                                apellidos = p.apellidos,
                                ruta_id = idLocal,
                                orden_visita = p.orden.toLong(),
                                activo = 1L,
                                promedio_litros = 0.0,
                            )
                        }
                    }
                }
            }
            estadoApp.sincronizacionTerminada(reloj.ahoraMillis())
            Result.Success(Unit)
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            Result.Failure(AppError.Sincronizacion("No se pudo actualizar las rutas."))
        }
    }

    private companion object {
        const val PRODUCTORES_POR_PAGINA = 100
        const val TURNO_UNICO = "primera_vuelta"
    }
}

class JornadaRepositoryImpl(
    private val db: LactoColusDb,
    private val dispatchers: DispatcherProvider,
    private val reloj: Reloj,
    private val cola: ColaSyncWriter,
    private val api: LactoColusApi,
    private val estadoApp: EstadoApp,
) : JornadaRepository {

    private val q get() = db.acopioQueries

    override fun jornadas(): Flow<List<Jornada>> =
        q.jornadas().asFlow().mapToList(dispatchers.io).map { list -> list.map { it.toDomain() } }

    override fun jornadaAbierta(): Flow<Jornada?> =
        q.jornadaAbierta().asFlow().mapToOneOrNull(dispatchers.io).map { it?.toDomain() }

    override suspend fun jornada(idLocal: String): Jornada? = withContext(dispatchers.io) {
        q.jornadaPorId(idLocal).executeAsOneOrNull()?.toDomain()
    }

    override suspend fun jornadaAbiertaAhora(): Jornada? = withContext(dispatchers.io) {
        q.jornadaAbierta().executeAsOneOrNull()?.toDomain()
    }

    override suspend fun abrirJornada(rutaId: String, turno: String, fechaOperativa: String, observaciones: String?): Result<Jornada> =
        withContext(dispatchers.io) {
            val existente = q.jornadaAbierta().executeAsOneOrNull()
            if (existente != null) {
                return@withContext Result.Failure(AppError.Conflicto("Ya hay una jornada abierta; ciérrala antes de abrir otra"))
            }
            val id = randomUuid()
            val ahora = reloj.ahoraMillis()
            q.insertJornada(
                id_local = id,
                id_remoto = null,
                uuid_publico = null,
                ruta_id = rutaId,
                turno = turno,
                vuelta = if (turno == "segunda_vuelta") 2L else 1L,
                fecha_operativa = fechaOperativa,
                estado = "abierta",
                observaciones = observaciones,
                total_litros = 0.0,
                total_entregas = 0L,
                abierta_en = ahora,
                cerrada_en = null,
                estado_sync = "PENDIENTE",
                clave_idempotencia = id,
            )
            val payload = jsonPayload(
                "ruta_id" to rutaId,
                "fecha_operativa" to fechaOperativa,
                "turno" to turno,
                "observaciones" to observaciones,
            )
            cola.encolar(TipoEntidadSync.JORNADA, id, id, payload)
            Result.Success(q.jornadaPorId(id).executeAsOne().toDomain())
        }

    override suspend fun cerrarJornada(idLocal: String): Result<Boolean> = withContext(dispatchers.io) {
        val j = q.jornadaPorId(idLocal).executeAsOneOrNull()
            ?: return@withContext Result.Failure(AppError.NoEncontrado)
        if (j.estado != "abierta") return@withContext Result.Success(true)

        val uuidPublico = j.uuid_publico
        if (uuidPublico == null) {
            // Aún no tiene uuid remoto (se creó sin conexión y no ha sincronizado todavía): no se
            // puede llamar al endpoint. Se cierra localmente y se encola para el próximo intento.
            cerrarLocalmenteYEncolar(idLocal)
            return@withContext Result.Success(false)
        }
        try {
            val remota = api.cerrarJornada(uuidPublico)
            aplicarCierreRemoto(idLocal, j.id_remoto, uuidPublico, remota)
            estadoApp.sincronizacionTerminada(reloj.ahoraMillis())
            Result.Success(true)
        } catch (e: CancellationException) {
            throw e
        } catch (e: ErrorRemotoException) {
            if (e.status == 422) {
                // El backend ya la tenía cerrada (reintento o carrera con otro dispositivo) —
                // transparente para el usuario: se refleja localmente como éxito, sin error.
                q.cerrarJornada(reloj.ahoraMillis(), idLocal)
                q.actualizarTotalesJornada(idLocal)
                q.marcarJornadaSync("CREADO", j.id_remoto, uuidPublico, idLocal)
                Result.Success(true)
            } else {
                cerrarLocalmenteYEncolar(idLocal)
                Result.Success(false)
            }
        } catch (e: Exception) {
            // Sin conexión u otro fallo de red: la leche ya se entregó, no se bloquea al
            // recolector — se cierra localmente y se reintenta en la próxima sincronización.
            cerrarLocalmenteYEncolar(idLocal)
            Result.Success(false)
        }
    }

    /** Escribe en la fila local los totales autoritativos que devolvió el backend al cerrar. */
    private fun aplicarCierreRemoto(idLocal: String, idRemoto: Long?, uuidPublico: String, remota: JornadaCerradaRemota) {
        val cerradaEn = remota.cerradaAt?.let { runCatching { Instant.parse(it).toEpochMilliseconds() }.getOrNull() } ?: reloj.ahoraMillis()
        q.aplicarCierreJornada(
            cerradaEn = cerradaEn,
            totalLitros = remota.litros.toDoubleOrNull() ?: 0.0,
            totalEntregas = remota.productoresAtendidos.toLong(),
            idRemoto = idRemoto ?: remota.id,
            uuid = uuidPublico,
            id = idLocal,
        )
    }

    /** Sin conexión (o error no-422): cierra la jornada localmente y encola el cierre remoto. */
    private fun cerrarLocalmenteYEncolar(idLocal: String) {
        q.cerrarJornada(reloj.ahoraMillis(), idLocal)
        q.actualizarTotalesJornada(idLocal)
        val payload = jsonPayload("accion" to "cerrar")
        cola.encolar(TipoEntidadSync.JORNADA, idLocal, idLocal, payload)
    }

    override suspend fun descargarJornadas(): Result<Unit> = withContext(dispatchers.io) {
        try {
            // Se piden TODAS las páginas primero (solo red) y se escriben en UNA transacción al
            // final: si se escribiera página por página, cada commit reemitiría los Flow que
            // leen `jornada`/`productor` (p. ej. la lista de productores de una ruta), causando
            // el parpadeo visto en pantalla mientras dura la descarga.
            val todas = mutableListOf<JornadaListadoRemota>()
            var pagina = 1
            var ultimaPagina = 1
            do {
                val respuesta = api.listarJornadas(pagina, JORNADAS_POR_PAGINA, estado = null, rutaId = null, desde = null, hasta = null)
                todas += respuesta.data
                ultimaPagina = respuesta.meta.ultimaPagina.coerceAtLeast(1)
                pagina++
            } while (pagina <= ultimaPagina)

            db.transaction {
                todas.forEach { remoto ->
                    val rutaLocal = db.acopioQueries.rutaPorIdRemoto(remoto.rutaId).executeAsOneOrNull()
                    val existente = q.jornadaPorIdRemoto(remoto.id).executeAsOneOrNull()
                    val rutaIdLocal = rutaLocal?.id_local ?: existente?.ruta_id
                    if (rutaIdLocal != null) {
                        guardarJornadaRemota(
                            existente, remoto.id, remoto.uuidPublico, rutaIdLocal, remoto.fechaOperativa, remoto.turno, remoto.estado,
                            totalEntregas = remoto.productoresAtendidos.toLong(),
                            totalLitros = remoto.litros.toDoubleOrNull() ?: 0.0,
                        )
                    }
                }
            }
            estadoApp.sincronizacionTerminada(reloj.ahoraMillis())
            Result.Success(Unit)
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            Result.Failure(AppError.Sincronizacion("No se pudo actualizar las jornadas."))
        }
    }

    override suspend fun iniciarJornadaHoy(rutaId: String, fechaOperativa: String): Result<Jornada> = withContext(dispatchers.io) {
        val idRemotoRuta = q.rutaPorId(rutaId).executeAsOneOrNull()?.id_remoto
            ?: return@withContext Result.Failure(AppError.Validacion("No tienes una ruta asignada.", "ruta"))
        try {
            val remota = api.abrirJornada(AbrirJornadaRequest(rutaId = idRemotoRuta.toString(), fechaOperativa = fechaOperativa, turno = TURNO_UNICO))
            val existente = q.jornadaPorIdRemoto(remota.id).executeAsOneOrNull()
            estadoApp.sincronizacionTerminada(reloj.ahoraMillis())
            Result.Success(
                guardarJornadaRemota(
                    existente, remota.id, remota.uuidPublico, rutaId, remota.fechaOperativa, remota.turno, remota.estado,
                    totalEntregas = remota.productoresAtendidos.toLong(),
                    totalLitros = remota.litros.toDoubleOrNull() ?: 0.0,
                ),
            )
        } catch (e: CancellationException) {
            throw e
        } catch (e: ErrorRemotoException) {
            if (e.status == 422) {
                resolverConflicto(rutaId, idRemotoRuta, fechaOperativa)
            } else {
                Result.Success(crearJornadaLocalPendiente(rutaId, idRemotoRuta, fechaOperativa))
            }
        } catch (e: Exception) {
            Result.Success(crearJornadaLocalPendiente(rutaId, idRemotoRuta, fechaOperativa))
        }
    }

    /** 422: otro canal ya abrió la jornada de hoy para esta ruta — la adoptamos en vez de fallar. */
    private suspend fun resolverConflicto(rutaId: String, idRemotoRuta: Long, fechaOperativa: String): Result<Jornada> {
        val pagina = api.listarJornadas(1, 1, estado = "abierta", rutaId = idRemotoRuta, desde = fechaOperativa, hasta = fechaOperativa)
        val remota = pagina.data.firstOrNull()
            ?: return Result.Success(crearJornadaLocalPendiente(rutaId, idRemotoRuta, fechaOperativa))
        val existente = q.jornadaPorIdRemoto(remota.id).executeAsOneOrNull()
        estadoApp.sincronizacionTerminada(reloj.ahoraMillis())
        return Result.Success(
            guardarJornadaRemota(
                existente, remota.id, remota.uuidPublico, rutaId, remota.fechaOperativa, remota.turno, remota.estado,
                totalEntregas = remota.productoresAtendidos.toLong(),
                totalLitros = remota.litros.toDoubleOrNull() ?: 0.0,
            ),
        )
    }

    /**
     * Upsert por `id_remoto`: crea si no existía localmente, conserva lo local si ya existía.
     * `totalEntregas`/`totalLitros` vienen siempre del backend (`productores_atendidos`/`litros`
     * en la respuesta) — antes se heredaban de `existente` (o `0` si no había fila previa), así
     * que una jornada recién descargada (o tras borrar los datos locales) siempre mostraba "0
     * entregas · 0,0 L" sin importar lo que el backend realmente tuviera registrado.
     */
    private fun guardarJornadaRemota(
        existente: DbJornada?,
        idRemoto: Long,
        uuidPublico: String,
        rutaIdLocal: String,
        fechaOperativa: String,
        turno: String,
        estado: String,
        totalEntregas: Long,
        totalLitros: Double,
    ): Jornada {
        val idLocal = existente?.id_local ?: randomUuid()
        val ahora = reloj.ahoraMillis()
        q.insertJornada(
            id_local = idLocal,
            id_remoto = idRemoto,
            uuid_publico = uuidPublico,
            ruta_id = rutaIdLocal,
            turno = turno,
            vuelta = if (turno == "segunda_vuelta") 2L else 1L,
            fecha_operativa = fechaOperativa,
            estado = estado,
            observaciones = existente?.observaciones,
            total_litros = totalLitros,
            total_entregas = totalEntregas,
            abierta_en = existente?.abierta_en ?: ahora,
            cerrada_en = existente?.cerrada_en,
            estado_sync = "CREADO",
            clave_idempotencia = existente?.clave_idempotencia ?: idLocal,
        )
        return q.jornadaPorId(idLocal).executeAsOne().toDomain()
    }

    /** Sin conexión: se crea localmente en PENDIENTE y se encola para sincronizar después. */
    private fun crearJornadaLocalPendiente(rutaId: String, idRemotoRuta: Long, fechaOperativa: String): Jornada {
        val id = randomUuid()
        val ahora = reloj.ahoraMillis()
        q.insertJornada(
            id_local = id,
            id_remoto = null,
            uuid_publico = null,
            ruta_id = rutaId,
            turno = TURNO_UNICO,
            vuelta = 1L,
            fecha_operativa = fechaOperativa,
            estado = "abierta",
            observaciones = null,
            total_litros = 0.0,
            total_entregas = 0L,
            abierta_en = ahora,
            cerrada_en = null,
            estado_sync = "PENDIENTE",
            clave_idempotencia = id,
        )
        val payload = jsonPayload(
            "ruta_id" to idRemotoRuta.toString(),
            "fecha_operativa" to fechaOperativa,
            "turno" to TURNO_UNICO,
        )
        cola.encolar(TipoEntidadSync.JORNADA, id, id, payload)
        return q.jornadaPorId(id).executeAsOne().toDomain()
    }

    private companion object {
        const val JORNADAS_POR_PAGINA = 100
        const val TURNO_UNICO = "primera_vuelta"
    }
}

class EntregaRepositoryImpl(
    private val db: LactoColusDb,
    private val dispatchers: DispatcherProvider,
    private val reloj: Reloj,
    private val cola: ColaSyncWriter,
    private val sync: SyncRepository,
    private val appScope: CoroutineScope,
) : EntregaRepository {

    private val q get() = db.acopioQueries

    override fun entregasDeJornada(jornadaId: String): Flow<List<Entrega>> =
        q.entregasDeJornada(jornadaId).asFlow().mapToList(dispatchers.io).map { list -> list.map { it.toDomain() } }

    override suspend fun entregaDeProductor(jornadaId: String, productorId: String): Entrega? =
        withContext(dispatchers.io) {
            q.entregaDeProductorEnJornada(jornadaId, productorId).executeAsOneOrNull()?.toDomain()
        }

    override suspend fun registrarEntrega(
        jornadaId: String,
        productorId: String,
        litros: Double?,
        noEntrego: Boolean,
        observacion: String?,
    ): Result<Entrega> = withContext(dispatchers.io) {
        val id = randomUuid()
        val ahora = reloj.ahoraMillis()
        db.transaction {
            q.insertEntrega(
                id_local = id,
                id_remoto = null,
                jornada_id = jornadaId,
                productor_id = productorId,
                litros = litros,
                no_entrego = if (noEntrego) 1L else 0L,
                recolectada_en = ahora,
                observacion = observacion,
                litros_anterior = null,
                motivo_correccion = null,
                corregida_en = null,
                estado_sync = "PENDIENTE",
                sync_mensaje = null,
                sync_intentos = 0L,
                clave_idempotencia = id,
            )
            q.actualizarTotalesJornada(jornadaId)
        }
        val payload = jsonPayload(
            "uuid_cliente" to id,
            "jornada_id" to jornadaId,
            "productor_id" to productorId,
            "litros" to Formato.decimal(litros ?: 0.0, 3).replace(',', '.'),
            "recolectada_at" to fechaHoraIso(ahora),
            "observacion" to (observacion ?: ""),
        )
        cola.encolar(TipoEntidadSync.ENTREGA, id, id, payload)
        // Sube de inmediato en segundo plano (spec: "el recolector no pulsa nada"). No se espera
        // aquí — appScope, no dispatchers.io de este withContext — para no retrasar el retorno;
        // si falla o no hay red, el registro ya quedó en cola_sync y se reintentará como siempre.
        appScope.launch { sync.sincronizarTodo() }
        Result.Success(q.entregaPorId(id).executeAsOne().toDomain())
    }

    override suspend fun corregirEntrega(idLocal: String, litros: Double, observacion: String?, motivo: String): Result<Unit> =
        withContext(dispatchers.io) {
            val entrega = q.entregaPorId(idLocal).executeAsOneOrNull()
                ?: return@withContext Result.Failure(AppError.NoEncontrado)
            db.transaction {
                q.corregirEntrega(litros, observacion, motivo, reloj.ahoraMillis(), idLocal)
                q.actualizarTotalesJornada(entrega.jornada_id)
            }
            // reencolar la corrección para reenvío
            val payload = jsonPayload(
                "uuid_cliente" to entrega.clave_idempotencia,
                "jornada_id" to entrega.jornada_id,
                "productor_id" to entrega.productor_id,
                "litros" to Formato.decimal(litros, 3).replace(',', '.'),
                "recolectada_at" to fechaHoraIso(entrega.recolectada_en),
                "observacion" to (observacion ?: ""),
                "motivo_correccion" to motivo,
            )
            cola.encolar(TipoEntidadSync.ENTREGA, idLocal, entrega.clave_idempotencia, payload)
            Result.Success(Unit)
        }
}
