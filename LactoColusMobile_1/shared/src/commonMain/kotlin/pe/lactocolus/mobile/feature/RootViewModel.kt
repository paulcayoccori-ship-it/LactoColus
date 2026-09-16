package pe.lactocolus.mobile.feature

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.launchIn
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.flow.onEach
import kotlinx.coroutines.flow.stateIn
import pe.lactocolus.mobile.core.ui.ConnectionState
import pe.lactocolus.mobile.core.ui.EstadoConexionUi
import pe.lactocolus.mobile.data.sync.ConnectivityObserver
import pe.lactocolus.mobile.data.sync.EstadoApp
import pe.lactocolus.mobile.domain.model.Rol
import pe.lactocolus.mobile.domain.model.Usuario
import pe.lactocolus.mobile.domain.usecase.ObservarPendientes
import pe.lactocolus.mobile.domain.usecase.ObservarSesion
import pe.lactocolus.mobile.domain.usecase.SincronizarTodo

sealed interface SesionUi {
    data object Cargando : SesionUi
    data object SinSesion : SesionUi
    data class Autenticado(val usuario: Usuario) : SesionUi

    /** Logged in, but [Usuario.rol] has no mobile flow (see [Rol.resolverDesdeRoles]). */
    data class SinAccesoMovil(val usuario: Usuario) : SesionUi
}

/**
 * Estado raíz: sesión + tira de conexión (spec §6). Al recuperar conexión dispara la cola
 * de sincronización (spec §3).
 */
class RootViewModel(
    observarSesion: ObservarSesion,
    observarPendientes: ObservarPendientes,
    private val estadoApp: EstadoApp,
    connectivity: ConnectivityObserver,
    private val sincronizarTodo: SincronizarTodo,
) : ViewModel() {

    val sesionUi: StateFlow<SesionUi> = observarSesion()
        .map { usuario ->
            when {
                usuario == null -> SesionUi.SinSesion
                usuario.rol == Rol.SIN_ACCESO -> SesionUi.SinAccesoMovil(usuario)
                else -> SesionUi.Autenticado(usuario)
            }
        }
        .stateIn(viewModelScope, SharingStarted.Eagerly, SesionUi.Cargando)

    val tira: StateFlow<EstadoConexionUi> = combine(
        estadoApp.conexion,
        observarPendientes(),
        estadoApp.ultimaSincronizacion,
        estadoApp.sync,
    ) { conexion, pendientes, ultima, sync ->
        EstadoConexionUi(conexion, pendientes, ultima, sync)
    }.stateIn(
        viewModelScope,
        SharingStarted.WhileSubscribed(5000),
        EstadoConexionUi(ConnectionState.CON_CONEXION, 0, null, pe.lactocolus.mobile.core.ui.SyncState.Inactivo),
    )

    init {
        // `previa` arranca en `false` (no en `true`) a propósito: así la PRIMERA emisión de
        // `connectivity.observar()` — que ya refleja la conectividad real al abrir la app, ver
        // ConnectivityObserver.android.kt — también sincroniza si hay pendientes, en vez de que
        // "!previa" la ignore por ser igual al valor inicial. sincronizarTodo() no hace nada si
        // la cola está vacía, así que disparar de más aquí es gratis.
        var previa = false
        connectivity.observar().onEach { conectado ->
            estadoApp.actualizarConexion(if (conectado) ConnectionState.CON_CONEXION else ConnectionState.SIN_CONEXION)
            if (conectado && !previa) sincronizarTodo()
            previa = conectado
        }.launchIn(viewModelScope)
    }
}
