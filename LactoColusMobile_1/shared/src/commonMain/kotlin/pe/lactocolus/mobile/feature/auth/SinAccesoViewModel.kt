package pe.lactocolus.mobile.feature.auth

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.launch
import pe.lactocolus.mobile.domain.usecase.CerrarSesion

class SinAccesoViewModel(private val cerrarSesionUC: CerrarSesion) : ViewModel() {
    fun volverAlLogin() = viewModelScope.launch { cerrarSesionUC() }
}
