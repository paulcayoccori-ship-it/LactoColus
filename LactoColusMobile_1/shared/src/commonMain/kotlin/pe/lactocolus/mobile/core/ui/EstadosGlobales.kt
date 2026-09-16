package pe.lactocolus.mobile.core.ui

/**
 * App-wide observable states (spec §6). Rendered permanently in the connection strip under
 * the top bar and consulted by screens to pick empty / error / offline layouts.
 */
enum class ConnectionState { CON_CONEXION, SIN_CONEXION }

sealed interface SyncState {
    data object Inactivo : SyncState
    data object Sincronizando : SyncState
    data object SincronizacionCorrecta : SyncState
    data class DatosPendientes(val cantidad: Int) : SyncState
    data class ErrorSincronizacion(val mensaje: String) : SyncState
}

/** Screen-level content state, shared by every list/detail screen. */
sealed interface ContentState<out T> {
    data object Cargando : ContentState<Nothing>
    data class Contenido<T>(val valor: T) : ContentState<T>
    data object SinResultados : ContentState<Nothing>
    data class ErrorServidor(val mensaje: String) : ContentState<Nothing>
    data object SinConexion : ContentState<Nothing>
    data object SesionVencida : ContentState<Nothing>
}

/** Snapshot rendered by the connection strip. */
data class EstadoConexionUi(
    val conexion: ConnectionState,
    val pendientes: Int,
    val ultimaSincronizacionMillis: Long?,
    val sync: SyncState,
)
