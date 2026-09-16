package pe.lactocolus.mobile.feature.productor

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch
import pe.lactocolus.mobile.core.common.Result
import pe.lactocolus.mobile.core.ui.aMensaje
import pe.lactocolus.mobile.domain.model.Liquidacion
import pe.lactocolus.mobile.domain.usecase.MarcarComunicadoLeido
import pe.lactocolus.mobile.domain.usecase.ObservarAnalisis
import pe.lactocolus.mobile.domain.usecase.ObservarComunicados
import pe.lactocolus.mobile.domain.usecase.ObservarLiquidaciones
import pe.lactocolus.mobile.domain.usecase.ObservarNoLeidos
import pe.lactocolus.mobile.domain.usecase.ObservarRanking
import pe.lactocolus.mobile.domain.usecase.ObservarRutasRanking
import pe.lactocolus.mobile.domain.usecase.ObservarSolicitudesTraslado
import pe.lactocolus.mobile.domain.usecase.ObtenerLiquidacion
import pe.lactocolus.mobile.domain.usecase.SolicitarTraslado

data class TrasladoUi(
    val destino: String = "",
    val fecha: String = "",
    val motivo: String = "",
    val enviando: Boolean = false,
    val error: String? = null,
    val enviado: Boolean = false,
)

class ProductorViewModel(
    observarComunicados: ObservarComunicados,
    observarNoLeidos: ObservarNoLeidos,
    observarLiquidaciones: ObservarLiquidaciones,
    observarAnalisis: ObservarAnalisis,
    observarSolicitudes: ObservarSolicitudesTraslado,
    observarRutasRanking: ObservarRutasRanking,
    private val observarRankingUC: ObservarRanking,
    private val marcarLeidoUC: MarcarComunicadoLeido,
    private val obtenerLiquidacionUC: ObtenerLiquidacion,
    private val solicitarTrasladoUC: SolicitarTraslado,
    val anticipacionDias: Int,
) : ViewModel() {

    val comunicados = observarComunicados().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())
    val noLeidos = observarNoLeidos().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), 0)
    val liquidaciones = observarLiquidaciones().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())
    val miCalidad = observarAnalisis().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())
    val solicitudes = observarSolicitudes().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())
    val rutasRanking = observarRutasRanking().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    // ranking
    private val _periodo = MutableStateFlow("semanal")
    private val _fecha = MutableStateFlow("2026-09-07")
    private val _rutaFiltro = MutableStateFlow<String?>(null)
    val periodo = _periodo.asStateFlow()
    val rutaFiltro = _rutaFiltro.asStateFlow()

    fun rankingFlow() = observarRankingUC(_periodo.value, _fecha.value, _rutaFiltro.value)
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    fun cambiarPeriodo(p: String) {
        _periodo.value = p
        _fecha.value = when (p) { "diario" -> "2026-09-10"; "mensual" -> "2026-09-01"; else -> "2026-09-07" }
    }
    fun cambiarRutaFiltro(r: String?) { _rutaFiltro.value = r }

    fun marcarLeido(id: String) = viewModelScope.launch { marcarLeidoUC(id) }

    private val _liquidacion = MutableStateFlow<Liquidacion?>(null)
    val liquidacion = _liquidacion.asStateFlow()
    fun cargarLiquidacion(id: String) = viewModelScope.launch { _liquidacion.value = obtenerLiquidacionUC(id) }

    // traslado
    private val _traslado = MutableStateFlow(TrasladoUi())
    val traslado = _traslado.asStateFlow()

    fun onDestino(v: String) { _traslado.value = _traslado.value.copy(destino = v, error = null) }
    fun onFechaTraslado(v: String) { _traslado.value = _traslado.value.copy(fecha = v, error = null) }
    fun onMotivo(v: String) { _traslado.value = _traslado.value.copy(motivo = v, error = null) }

    fun enviarTraslado(rutaOrigen: String) {
        val s = _traslado.value
        if (s.enviando) return
        _traslado.value = s.copy(enviando = true, error = null)
        viewModelScope.launch {
            when (val r = solicitarTrasladoUC(rutaOrigen, s.destino, s.fecha, s.motivo)) {
                is Result.Success -> _traslado.value = _traslado.value.copy(enviando = false, enviado = true)
                is Result.Failure -> _traslado.value = _traslado.value.copy(enviando = false, error = r.error.aMensaje())
            }
        }
    }
    fun reiniciarTraslado() { _traslado.value = TrasladoUi() }
}
