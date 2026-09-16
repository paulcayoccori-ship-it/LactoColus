package pe.lactocolus.mobile.feature.perfil

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch
import pe.lactocolus.mobile.core.designsystem.TemaApp
import pe.lactocolus.mobile.domain.usecase.BorrarDatosLocales
import pe.lactocolus.mobile.domain.usecase.CerrarSesion
import pe.lactocolus.mobile.domain.usecase.ObservarSesion

class PerfilViewModel(
    observarSesion: ObservarSesion,
    private val cerrarSesionUC: CerrarSesion,
    private val borrarDatosLocalesUC: BorrarDatosLocales,
) : ViewModel() {

    val usuario = observarSesion().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), null)

    private val _tema = MutableStateFlow(TemaApp.SISTEMA)
    val tema = _tema.asStateFlow()
    fun cambiarTema(t: TemaApp) { _tema.value = t }

    val version = "1.0.0 (móvil, entrega inicial)"

    private val _mostrarConfirmacionBorrado = MutableStateFlow(false)
    val mostrarConfirmacionBorrado = _mostrarConfirmacionBorrado.asStateFlow()

    fun cerrarSesion() = viewModelScope.launch { cerrarSesionUC() }

    fun solicitarBorrarDatos() { _mostrarConfirmacionBorrado.value = true }
    fun cancelarBorrarDatos() { _mostrarConfirmacionBorrado.value = false }
    fun confirmarBorrarDatos() = viewModelScope.launch {
        borrarDatosLocalesUC()
        _mostrarConfirmacionBorrado.value = false
    }
}
