package pe.lactocolus.mobile.domain.repository

import kotlinx.coroutines.flow.Flow
import pe.lactocolus.mobile.core.common.Result
import pe.lactocolus.mobile.domain.model.Analisis
import pe.lactocolus.mobile.domain.model.ColaSyncItem
import pe.lactocolus.mobile.domain.model.Comunicado
import pe.lactocolus.mobile.domain.model.Entrega
import pe.lactocolus.mobile.domain.model.Jornada
import pe.lactocolus.mobile.domain.model.Liquidacion
import pe.lactocolus.mobile.domain.model.ParametroAnalisis
import pe.lactocolus.mobile.domain.model.Productor
import pe.lactocolus.mobile.domain.model.RankingEntry
import pe.lactocolus.mobile.domain.model.ResultadoAnalisis
import pe.lactocolus.mobile.domain.model.Rol
import pe.lactocolus.mobile.domain.model.Ruta
import pe.lactocolus.mobile.domain.model.SolicitudTraslado
import pe.lactocolus.mobile.domain.model.Usuario

/**
 * Repository contracts. Reads expose [Flow] straight from SQLite; writes persist locally and
 * enqueue a [ColaSyncItem]. The `remote` layer never writes to a screen — only the sync
 * engine calls it, and it only feeds the local store.
 */

interface AuthRepository {
    fun sesion(): Flow<Usuario?>
    suspend fun iniciarSesion(correo: String, clave: String): Result<Usuario>
    suspend fun cerrarSesion()
    suspend fun sesionActual(): Usuario?
    /** Borra todas las tablas locales (catálogos, pendientes de sync incluidos) y la sesión. */
    suspend fun borrarDatosLocales()
}

interface RutaRepository {
    fun rutas(): Flow<List<Ruta>>
    suspend fun ruta(idLocal: String): Ruta?
    fun productoresDeRuta(rutaId: String): Flow<List<Productor>>
    fun buscarProductores(query: String): Flow<List<Productor>>
    suspend fun productor(idLocal: String): Productor?

    /**
     * Downloads every page of `GET /api/v1/productores` and upserts it into SQLite by
     * `id_remoto` (never duplicates). Offline-first: on failure (no network, 401, 403) it
     * returns [Result.Failure] without touching what's already stored — callers ignore the
     * failure and keep reading the local cache, they never block on this.
     */
    suspend fun descargarProductores(): Result<Unit>

    /**
     * Downloads `GET /api/v1/acopios/rutas` (unpaginated, already scoped to the authenticated
     * recolector) and upserts by `id_remoto`. Also assigns `ruta_id`/`orden_visita` on the
     * nested productores — the only source for those two columns, `descargarProductores()`
     * never returns them.
     */
    suspend fun descargarRutas(): Result<Unit>
}

interface JornadaRepository {
    fun jornadas(): Flow<List<Jornada>>
    fun jornadaAbierta(): Flow<Jornada?>
    suspend fun jornada(idLocal: String): Jornada?

    /** One-shot read of the currently open jornada, if any — for use-case-level decisions. */
    suspend fun jornadaAbiertaAhora(): Jornada?

    suspend fun abrirJornada(rutaId: String, turno: String, fechaOperativa: String, observaciones: String?): Result<Jornada>
    suspend fun cerrarJornada(idLocal: String): Result<Unit>

    /** Downloads `GET /api/v1/acopios/jornadas` (every page) and upserts by `id_remoto`. */
    suspend fun descargarJornadas(): Result<Unit>

    /**
     * Starts today's jornada with no form input (spec: one button, `turno` fixed to
     * `primera_vuelta`). Tries the real backend first; on a 422 conflict (someone already
     * opened today's jornada for this ruta) it fetches and adopts that jornada instead of
     * failing. On any other error (no connectivity) it falls back to a local PENDIENTE row
     * queued for later sync — transparent to the caller either way.
     */
    suspend fun iniciarJornadaHoy(rutaId: String, fechaOperativa: String): Result<Jornada>
}

interface EntregaRepository {
    fun entregasDeJornada(jornadaId: String): Flow<List<Entrega>>
    suspend fun entregaDeProductor(jornadaId: String, productorId: String): Entrega?
    suspend fun registrarEntrega(
        jornadaId: String,
        productorId: String,
        litros: Double?,
        noEntrego: Boolean,
        observacion: String?,
    ): Result<Entrega>
    suspend fun corregirEntrega(idLocal: String, litros: Double, observacion: String?, motivo: String): Result<Unit>
}

interface AnalisisRepository {
    fun analisis(): Flow<List<Analisis>>
    fun analisisDeProductor(productorId: String): Flow<List<Analisis>>
    suspend fun analisisPorId(idLocal: String): Analisis?
    fun resumenHoy(): Flow<ResumenCalidad>
    suspend fun registrarAnalisis(
        productorId: String,
        fechaMillis: Long,
        equipo: String?,
        parametros: List<ParametroAnalisis>,
        aguaAnadida: Double,
        observaciones: String?,
    ): Result<Analisis>
}

data class ResumenCalidad(
    val hoy: Int,
    val observados: Int,
    val pendientes: Int,
    val sinSincronizar: Int,
)

interface ComunicadoRepository {
    fun comunicados(): Flow<List<Comunicado>>
    fun noLeidos(): Flow<Int>
    suspend fun comunicado(idLocal: String): Comunicado?
    suspend fun marcarLeido(idLocal: String)
}

interface LiquidacionRepository {
    fun liquidaciones(): Flow<List<Liquidacion>>
    suspend fun liquidacion(idLocal: String): Liquidacion?
}

interface RankingRepository {
    fun ranking(periodo: String, fecha: String, ruta: String?): Flow<List<RankingEntry>>
    fun rutasDisponibles(): Flow<List<String>>
}

interface TrasladoRepository {
    fun solicitudes(): Flow<List<SolicitudTraslado>>
    val anticipacionDias: Int
    suspend fun solicitar(rutaOrigen: String, rutaDestino: String, fechaDeseada: String, motivo: String): Result<SolicitudTraslado>
}

interface SyncRepository {
    fun cola(): Flow<List<ColaSyncItem>>
    fun pendientes(): Flow<Int>
    val ultimaSincronizacionMillis: Flow<Long?>
    suspend fun sincronizarTodo(): Result<Unit>
    suspend fun reintentar(idLocal: String): Result<Unit>
}

/** Debug-only bootstrap of the local store with mock data (spec §9). */
interface SeedRepository {
    suspend fun sembrarSiVacio(rol: Rol)
}
