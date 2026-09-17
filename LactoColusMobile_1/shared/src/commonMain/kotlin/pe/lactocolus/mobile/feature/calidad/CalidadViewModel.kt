package pe.lactocolus.mobile.feature.calidad

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch
import pe.lactocolus.mobile.core.common.Reloj
import pe.lactocolus.mobile.core.common.Result
import pe.lactocolus.mobile.core.ui.aMensaje
import pe.lactocolus.mobile.domain.model.Analisis
import pe.lactocolus.mobile.domain.model.EntregaCalidad
import pe.lactocolus.mobile.domain.usecase.BuscarProductores
import pe.lactocolus.mobile.domain.usecase.DescargarJornadasCalidad
import pe.lactocolus.mobile.domain.usecase.DescargarProductores
import pe.lactocolus.mobile.domain.usecase.ObservarAnalisis
import pe.lactocolus.mobile.domain.usecase.ObservarEntregasDeJornadaCalidad
import pe.lactocolus.mobile.domain.usecase.ObservarJornadasCalidad
import pe.lactocolus.mobile.domain.usecase.ObservarResumenCalidad
import pe.lactocolus.mobile.domain.usecase.ObtenerAnalisis
import pe.lactocolus.mobile.domain.usecase.ObtenerProductorPorIdRemoto
import pe.lactocolus.mobile.domain.usecase.ParametroCalidad
import pe.lactocolus.mobile.domain.usecase.RegistrarAnalisis
import pe.lactocolus.mobile.domain.usecase.ValidarAnalisis

data class NuevoAnalisisUi(
    val productorId: String = "",
    val productorNombre: String = "",
    val entregaId: Long? = null,
    val valores: Map<ParametroCalidad, String> = emptyMap(),
    val erroresCampo: Map<ParametroCalidad, String> = emptyMap(),
    val observaciones: String = "",
    val guardando: Boolean = false,
    val error: String? = null,
    val analisisCreado: Analisis? = null,
) {
    /** Refleja [pe.lactocolus.mobile.domain.usecase.ValidarAnalisis]; el formulario no decide esto. */
    val guardarHabilitado: Boolean get() = erroresCampo.isEmpty() && !guardando
}

class CalidadViewModel(
    observarResumen: ObservarResumenCalidad,
    observarAnalisis: ObservarAnalisis,
    observarJornadas: ObservarJornadasCalidad,
    private val observarEntregasDeJornada: ObservarEntregasDeJornadaCalidad,
    private val buscarProductoresUC: BuscarProductores,
    private val obtenerAnalisis: ObtenerAnalisis,
    private val obtenerProductorPorIdRemotoUC: ObtenerProductorPorIdRemoto,
    private val registrarAnalisisUC: RegistrarAnalisis,
    private val validarAnalisisUC: ValidarAnalisis,
    private val descargarProductoresUC: DescargarProductores,
    private val descargarJornadasUC: DescargarJornadasCalidad,
    private val reloj: Reloj,
) : ViewModel() {

    val resumen = observarResumen().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), null)
    val historial = observarAnalisis().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())
    val jornadas = observarJornadas().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    init {
        // Offline-first: la pantalla ya lee de SQLite vía buscarProductoresUC; esto solo
        // enriquece esa tabla en segundo plano. Si falla (sin red, 401, 403) no bloquea nada —
        // el Flow reactivo simplemente sigue mostrando lo que ya había en SQLite.
        viewModelScope.launch { descargarProductoresUC() }
    }

    fun buscar(q: String) = buscarProductoresUC(q).stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    fun entregasDeJornada(jornadaIdRemoto: Long): StateFlow<List<EntregaCalidad>> =
        observarEntregasDeJornada(jornadaIdRemoto).stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    // ---- descarga de jornadas de calidad (entrada a la pantalla + deslizar) ----
    private val _refrescandoJornadas = MutableStateFlow(false)
    val refrescandoJornadas = _refrescandoJornadas.asStateFlow()

    fun refrescarJornadas() {
        if (_refrescandoJornadas.value) return
        viewModelScope.launch {
            _refrescandoJornadas.value = true
            descargarJornadasUC()
            _refrescandoJornadas.value = false
        }
    }

    private val _detalle = MutableStateFlow<Analisis?>(null)
    val detalle = _detalle.asStateFlow()
    fun cargarDetalle(id: String) = viewModelScope.launch { _detalle.value = obtenerAnalisis(id) }

    private val _nuevo = MutableStateFlow(NuevoAnalisisUi())
    val nuevo = _nuevo.asStateFlow()

    /**
     * Llegada desde el nivel 2 (entrega de una jornada de calidad): `productorIdRemoto` es el id
     * del backend, no el uuid local que el formulario necesita — se resuelve aquí contra el
     * catálogo ya descargado (`descargarProductoresUC` en el init). Si aún no está en caché,
     * `productorId` queda vacío y el guardado falla con el mismo mensaje claro que ya existía
     * ("Elige un productor") en vez de mandar un análisis mal ligado.
     */
    fun preparar(productorIdRemoto: Long, nombre: String, entregaId: Long) {
        _nuevo.value = NuevoAnalisisUi(
            productorNombre = nombre,
            entregaId = entregaId,
            erroresCampo = validarAnalisisUC(emptyMap()).errores,
        )
        viewModelScope.launch {
            val local = obtenerProductorPorIdRemotoUC(productorIdRemoto)
            _nuevo.value = _nuevo.value.copy(productorId = local?.idLocal ?: "")
        }
    }
    fun onValor(param: ParametroCalidad, v: String) {
        val valores = _nuevo.value.valores + (param to v.replace(',', '.'))
        _nuevo.value = _nuevo.value.copy(
            valores = valores,
            erroresCampo = validarAnalisisUC(valores).errores,
            error = null,
        )
    }
    fun onObservaciones(v: String) { _nuevo.value = _nuevo.value.copy(observaciones = v) }

    fun guardar() {
        val s = _nuevo.value
        if (!s.guardarHabilitado) return
        _nuevo.value = s.copy(guardando = true, error = null)
        viewModelScope.launch {
            when (val r = registrarAnalisisUC(s.productorId, reloj.ahoraMillis(), null, s.valores, s.observaciones.ifBlank { null }, s.entregaId)) {
                is Result.Success -> _nuevo.value = _nuevo.value.copy(guardando = false, analisisCreado = r.value)
                is Result.Failure -> _nuevo.value = _nuevo.value.copy(guardando = false, error = r.error.aMensaje())
            }
        }
    }
    fun consumirCreado() { _nuevo.value = _nuevo.value.copy(analisisCreado = null) }
}
