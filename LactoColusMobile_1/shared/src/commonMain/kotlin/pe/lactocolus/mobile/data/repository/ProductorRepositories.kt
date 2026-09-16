package pe.lactocolus.mobile.data.repository

import app.cash.sqldelight.coroutines.asFlow
import app.cash.sqldelight.coroutines.mapToList
import app.cash.sqldelight.coroutines.mapToOne
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.withContext
import pe.lactocolus.mobile.core.common.AppError
import pe.lactocolus.mobile.core.common.DispatcherProvider
import pe.lactocolus.mobile.core.common.Reloj
import pe.lactocolus.mobile.core.common.Result
import pe.lactocolus.mobile.core.common.randomUuid
import pe.lactocolus.mobile.data.local.toDomain
import pe.lactocolus.mobile.db.LactoColusDb
import pe.lactocolus.mobile.domain.model.Comunicado
import pe.lactocolus.mobile.domain.model.Liquidacion
import pe.lactocolus.mobile.domain.model.RankingEntry
import pe.lactocolus.mobile.domain.model.SolicitudTraslado
import pe.lactocolus.mobile.domain.model.TipoEntidadSync
import pe.lactocolus.mobile.domain.repository.ComunicadoRepository
import pe.lactocolus.mobile.domain.repository.LiquidacionRepository
import pe.lactocolus.mobile.domain.repository.RankingRepository
import pe.lactocolus.mobile.domain.repository.TrasladoRepository

class ComunicadoRepositoryImpl(
    private val db: LactoColusDb,
    private val dispatchers: DispatcherProvider,
    private val reloj: Reloj,
) : ComunicadoRepository {

    private val q get() = db.productorQueries

    override fun comunicados(): Flow<List<Comunicado>> =
        q.comunicados().asFlow().mapToList(dispatchers.io).map { list -> list.map { it.toDomain() } }

    override fun noLeidos(): Flow<Int> =
        q.contarNoLeidos().asFlow().mapToOne(dispatchers.io).map { it.toInt() }

    override suspend fun comunicado(idLocal: String): Comunicado? = withContext(dispatchers.io) {
        q.comunicadoPorId(idLocal).executeAsOneOrNull()?.toDomain()
    }

    override suspend fun marcarLeido(idLocal: String) = withContext(dispatchers.io) {
        q.marcarLeido(reloj.ahoraMillis(), idLocal)
        Unit
    }
}

class LiquidacionRepositoryImpl(
    private val db: LactoColusDb,
    private val dispatchers: DispatcherProvider,
) : LiquidacionRepository {

    private val q get() = db.productorQueries

    override fun liquidaciones(): Flow<List<Liquidacion>> =
        q.liquidaciones().asFlow().mapToList(dispatchers.io).map { list -> list.map { it.toDomain() } }

    override suspend fun liquidacion(idLocal: String): Liquidacion? = withContext(dispatchers.io) {
        q.liquidacionPorId(idLocal).executeAsOneOrNull()?.toDomain()
    }
}

class RankingRepositoryImpl(
    private val db: LactoColusDb,
    private val dispatchers: DispatcherProvider,
) : RankingRepository {

    private val q get() = db.productorQueries

    override fun ranking(periodo: String, fecha: String, ruta: String?): Flow<List<RankingEntry>> {
        val query = if (ruta.isNullOrBlank()) q.rankingPorPeriodo(periodo, fecha)
        else q.rankingPorPeriodoYRuta(periodo, fecha, ruta)
        return query.asFlow().mapToList(dispatchers.io).map { list -> list.map { it.toDomain() } }
    }

    override fun rutasDisponibles(): Flow<List<String>> =
        q.rutasEnRanking().asFlow().mapToList(dispatchers.io)
}

class TrasladoRepositoryImpl(
    private val db: LactoColusDb,
    private val dispatchers: DispatcherProvider,
    private val reloj: Reloj,
    private val cola: ColaSyncWriter,
    /** Plazo de anticipación configurable (backend/docs/solicitudes-traslado.md, 1–365 días). */
    override val anticipacionDias: Int = 10,
) : TrasladoRepository {

    private val q get() = db.productorQueries

    override fun solicitudes(): Flow<List<SolicitudTraslado>> =
        q.solicitudesTraslado().asFlow().mapToList(dispatchers.io).map { list -> list.map { it.toDomain() } }

    override suspend fun solicitar(rutaOrigen: String, rutaDestino: String, fechaDeseada: String, motivo: String): Result<SolicitudTraslado> =
        withContext(dispatchers.io) {
            val pendiente = q.solicitudesTraslado().executeAsList().any {
                it.estado == "pendiente" || it.estado == "aprobada"
            }
            if (pendiente) {
                return@withContext Result.Failure(AppError.Conflicto("Ya tienes una solicitud en trámite"))
            }
            val id = randomUuid()
            q.insertSolicitudTraslado(
                id_local = id,
                id_remoto = null,
                uuid = id,
                ruta_origen = rutaOrigen,
                ruta_destino = rutaDestino,
                fecha_deseada = fechaDeseada,
                motivo = motivo,
                estado = "pendiente",
                anticipacion_dias = anticipacionDias.toLong(),
                creada_en = reloj.ahoraMillis(),
                estado_sync = "PENDIENTE",
                sync_mensaje = null,
                clave_idempotencia = id,
            )
            val payload = jsonPayload(
                "uuid" to id,
                "ruta_solicitada_id" to rutaDestino,
                "fecha_efectiva" to fechaDeseada,
                "motivo" to motivo,
            )
            cola.encolar(TipoEntidadSync.TRASLADO, id, id, payload)
            Result.Success(q.solicitudTrasladoPorId(id).executeAsOne().toDomain())
        }
}
