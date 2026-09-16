package pe.lactocolus.mobile.feature.auth

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import pe.lactocolus.mobile.core.common.Result
import pe.lactocolus.mobile.core.ui.aMensaje
import pe.lactocolus.mobile.domain.model.Usuario
import pe.lactocolus.mobile.domain.usecase.IniciarSesion

data class LoginUiState(
    val correo: String = "",
    val clave: String = "",
    val cargando: Boolean = false,
    val error: String? = null,
    val usuario: Usuario? = null,
)

class AuthViewModel(
    private val iniciarSesion: IniciarSesion,
) : ViewModel() {

    private val _estado = MutableStateFlow(LoginUiState())
    val estado = _estado.asStateFlow()

    fun onCorreo(v: String) { _estado.value = _estado.value.copy(correo = v, error = null) }
    fun onClave(v: String) { _estado.value = _estado.value.copy(clave = v, error = null) }

    fun entrar() {
        val s = _estado.value
        if (s.cargando) return
        _estado.value = s.copy(cargando = true, error = null)
        viewModelScope.launch {
            when (val r = iniciarSesion(s.correo, s.clave)) {
                is Result.Success -> _estado.value = _estado.value.copy(cargando = false, usuario = r.value)
                is Result.Failure -> _estado.value = _estado.value.copy(cargando = false, error = r.error.aMensaje())
            }
        }
    }

    fun consumirUsuario() { _estado.value = _estado.value.copy(usuario = null) }
}
