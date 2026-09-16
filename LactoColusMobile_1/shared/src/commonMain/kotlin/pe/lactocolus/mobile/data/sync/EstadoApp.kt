package pe.lactocolus.mobile.data.sync

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import pe.lactocolus.mobile.core.ui.ConnectionState
import pe.lactocolus.mobile.core.ui.SyncState

/**
 * Single source of truth for the two app-wide observable states (spec §6). Injected as a
 * Koin singleton; the connection strip and every screen read from here.
 */
class EstadoApp {
    private val _conexion = MutableStateFlow(ConnectionState.CON_CONEXION)
    val conexion: StateFlow<ConnectionState> = _conexion.asStateFlow()

    private val _sync = MutableStateFlow<SyncState>(SyncState.Inactivo)
    val sync: StateFlow<SyncState> = _sync.asStateFlow()

    private val _ultimaSincronizacion = MutableStateFlow<Long?>(null)
    val ultimaSincronizacion: StateFlow<Long?> = _ultimaSincronizacion.asStateFlow()

    private val _sesionVencida = MutableStateFlow(false)
    val sesionVencida: StateFlow<Boolean> = _sesionVencida.asStateFlow()

    fun actualizarConexion(estado: ConnectionState) { _conexion.value = estado }
    fun estadoSync(estado: SyncState) { _sync.value = estado }
    fun sincronizacionTerminada(momento: Long) {
        _ultimaSincronizacion.value = momento
        _sync.value = SyncState.SincronizacionCorrecta
    }
    fun marcarSesionVencida() { _sesionVencida.value = true }
    fun limpiarSesionVencida() { _sesionVencida.value = false }
}
