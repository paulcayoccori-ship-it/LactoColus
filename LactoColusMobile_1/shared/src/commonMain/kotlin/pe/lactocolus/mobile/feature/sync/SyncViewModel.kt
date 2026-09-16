package pe.lactocolus.mobile.feature.sync

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch
import pe.lactocolus.mobile.core.common.Result
import pe.lactocolus.mobile.core.ui.aMensaje
import pe.lactocolus.mobile.domain.usecase.ObservarColaSync
import pe.lactocolus.mobile.domain.usecase.ObservarPendientes
import pe.lactocolus.mobile.domain.usecase.ReintentarSync
import pe.lactocolus.mobile.domain.usecase.SincronizarTodo

class SyncViewModel(
    observarCola: ObservarColaSync,
    observarPendientes: ObservarPendientes,
    private val sincronizarTodoUC: SincronizarTodo,
    private val reintentarUC: ReintentarSync,
) : ViewModel() {

    val cola = observarCola().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())
    val pendientes = observarPendientes().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), 0)

    private val _sincronizando = MutableStateFlow(false)
    val sincronizando = _sincronizando.asStateFlow()

    private val _mensaje = MutableStateFlow<String?>(null)
    val mensaje = _mensaje.asStateFlow()
    fun consumirMensaje() { _mensaje.value = null }

    fun sincronizarTodo() {
        if (_sincronizando.value) return
        _sincronizando.value = true
        viewModelScope.launch {
            when (val r = sincronizarTodoUC()) {
                is Result.Success -> _mensaje.value = "Sincronización completada"
                is Result.Failure -> _mensaje.value = r.error.aMensaje()
            }
            _sincronizando.value = false
        }
    }

    fun reintentar(idLocal: String) = viewModelScope.launch {
        when (val r = reintentarUC(idLocal)) {
            is Result.Success -> _mensaje.value = "Registro reenviado"
            is Result.Failure -> _mensaje.value = r.error.aMensaje()
        }
    }
}
