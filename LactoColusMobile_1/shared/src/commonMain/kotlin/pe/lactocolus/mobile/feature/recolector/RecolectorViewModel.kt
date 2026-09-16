package pe.lactocolus.mobile.feature.recolector

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.flatMapLatest
import kotlinx.coroutines.flow.flowOf
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch
import pe.lactocolus.mobile.core.common.Result
import pe.lactocolus.mobile.core.ui.aMensaje
import pe.lactocolus.mobile.domain.model.Entrega
import pe.lactocolus.mobile.domain.model.Jornada
import pe.lactocolus.mobile.domain.model.Productor
import pe.lactocolus.mobile.domain.model.Ruta
import pe.lactocolus.mobile.domain.usecase.AbrirJornada
import pe.lactocolus.mobile.domain.usecase.BuscarProductores
import pe.lactocolus.mobile.domain.usecase.CerrarJornada
import pe.lactocolus.mobile.domain.usecase.CorregirEntrega
import pe.lactocolus.mobile.domain.usecase.DescargarJornadas
import pe.lactocolus.mobile.domain.usecase.DescargarRutas
import pe.lactocolus.mobile.domain.usecase.IniciarJornadaDelDia
import pe.lactocolus.mobile.domain.usecase.ObservarEntregasDeJornada
import pe.lactocolus.mobile.domain.usecase.ObservarJornadaActiva
import pe.lactocolus.mobile.domain.usecase.ObservarJornadas
import pe.lactocolus.mobile.domain.usecase.ObservarProductoresDeRuta
import pe.lactocolus.mobile.domain.usecase.ObservarRutas
import pe.lactocolus.mobile.domain.usecase.ObtenerProductor
import pe.lactocolus.mobile.domain.usecase.RegistrarEntrega

data class RecolectorHomeUi(
    val cargando: Boolean = true,
    val jornada: Jornada? = null,
    val entregas: List<Entrega> = emptyList(),
    val rutas: List<Ruta> = emptyList(),
    val totalProductores: Int = 0,
) {
    val atendidos get() = entregas.size
    val pendientesPorVisitar get() = (totalProductores - atendidos).coerceAtLeast(0)
}

data class RegistroEntregaUi(
    val productor: Productor? = null,
    val jornadaId: String = "",
    val litros: String = "",
    val noEntrego: Boolean = false,
    val observacion: String = "",
    val guardando: Boolean = false,
    val error: String? = null,
    val errorCampo: String? = null,
    val entregaCreada: Entrega? = null,
)

@OptIn(ExperimentalCoroutinesApi::class)
class RecolectorViewModel(
    observarJornadaActiva: ObservarJornadaActiva,
    private val observarJornadas: ObservarJornadas,
    private val observarRutas: ObservarRutas,
    private val observarProductoresDeRuta: ObservarProductoresDeRuta,
    private val observarEntregas: ObservarEntregasDeJornada,
    private val buscarProductoresUC: BuscarProductores,
    private val obtenerProductorUC: ObtenerProductor,
    private val abrirJornadaUC: AbrirJornada,
    private val cerrarJornadaUC: CerrarJornada,
    private val registrarEntregaUC: RegistrarEntrega,
    private val corregirEntregaUC: CorregirEntrega,
    private val descargarRutasUC: DescargarRutas,
    private val descargarJornadasUC: DescargarJornadas,
    private val iniciarJornadaDelDiaUC: IniciarJornadaDelDia,
) : ViewModel() {

    val jornadas = observarJornadas().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())
    val rutas = observarRutas().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    val home = combine(
        observarJornadaActiva(),
        observarRutas(),
    ) { jornada, rutas -> jornada to rutas }
        .flatMapLatest { (jornada, rutas) ->
            if (jornada == null) flowOf(RecolectorHomeUi(cargando = false, jornada = null, rutas = rutas))
            else combine(
                observarEntregas(jornada.idLocal),
                observarProductoresDeRuta(jornada.rutaId),
            ) { entregas, prods ->
                RecolectorHomeUi(false, jornada, entregas, rutas, prods.size)
            }
        }
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), RecolectorHomeUi())

    fun productoresDeRuta(rutaId: String) =
        observarProductoresDeRuta(rutaId).stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    fun entregasDeJornada(jornadaId: String) =
        observarEntregas(jornadaId).stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    fun buscar(query: String) =
        buscarProductoresUC(query).stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    // ---- acciones ----
    private val _mensaje = MutableStateFlow<String?>(null)
    val mensaje = _mensaje.asStateFlow()
    fun consumirMensaje() { _mensaje.value = null }

    fun abrirJornada(rutaId: String, turno: String, fecha: String, obs: String?) = viewModelScope.launch {
        when (val r = abrirJornadaUC(rutaId, turno, fecha, obs)) {
            is Result.Success -> _mensaje.value = "Jornada abierta"
            is Result.Failure -> _mensaje.value = r.error.aMensaje()
        }
    }

    // ---- descarga de catálogo (rutas + jornadas) ----
    private val _refrescando = MutableStateFlow(false)
    val refrescando = _refrescando.asStateFlow()

    /** Dispara la descarga de rutas y jornadas desde el backend (Inicio, Mis rutas, deslizar). */
    fun refrescarCatalogo() {
        if (_refrescando.value) return
        viewModelScope.launch {
            _refrescando.value = true
            descargarRutasUC()
            descargarJornadasUC()
            _refrescando.value = false
        }
    }

    // ---- iniciar jornada del día (spec: un botón, sin formulario) ----
    private val _iniciandoJornada = MutableStateFlow(false)
    val iniciandoJornada = _iniciandoJornada.asStateFlow()

    fun iniciarJornadaDelDia() {
        if (_iniciandoJornada.value) return
        viewModelScope.launch {
            _iniciandoJornada.value = true
            when (val r = iniciarJornadaDelDiaUC()) {
                is Result.Success -> Unit // el home reacciona solo vía observarJornadaActiva()
                is Result.Failure -> _mensaje.value = r.error.aMensaje()
            }
            _iniciandoJornada.value = false
        }
    }

    fun cerrarJornada(jornadaId: String) = viewModelScope.launch {
        when (val r = cerrarJornadaUC(jornadaId)) {
            is Result.Success -> _mensaje.value = "Jornada cerrada"
            is Result.Failure -> _mensaje.value = r.error.aMensaje()
        }
    }

    // ---- registro de entrega ----
    private val _registro = MutableStateFlow(RegistroEntregaUi())
    val registro = _registro.asStateFlow()

    fun prepararRegistro(jornadaId: String, productor: Productor) {
        _registro.value = RegistroEntregaUi(productor = productor, jornadaId = jornadaId)
    }

    fun prepararRegistroPorId(jornadaId: String, productorId: String) {
        if (_registro.value.productor?.idLocal == productorId) return
        viewModelScope.launch {
            val prod = obtenerProductorUC(productorId)
            if (prod != null) _registro.value = RegistroEntregaUi(productor = prod, jornadaId = jornadaId)
        }
    }
    fun onLitros(v: String) { _registro.value = _registro.value.copy(litros = v.replace(',', '.'), error = null, errorCampo = null) }
    fun incrementarLitros(delta: Double) {
        val actual = _registro.value.litros.toDoubleOrNull() ?: 0.0
        _registro.value = _registro.value.copy(litros = ((actual + delta).coerceAtLeast(0.0)).toString())
    }
    fun onNoEntrego(v: Boolean) { _registro.value = _registro.value.copy(noEntrego = v) }
    fun onObservacion(v: String) { _registro.value = _registro.value.copy(observacion = v, error = null) }

    fun guardarEntrega() {
        val s = _registro.value
        val prod = s.productor ?: return
        if (s.guardando) return
        _registro.value = s.copy(guardando = true, error = null, errorCampo = null)
        viewModelScope.launch {
            val litros = if (s.noEntrego) null else s.litros.toDoubleOrNull()
            when (val r = registrarEntregaUC(s.jornadaId, prod.idLocal, litros, s.noEntrego, s.observacion.ifBlank { null })) {
                is Result.Success -> _registro.value = _registro.value.copy(guardando = false, entregaCreada = r.value)
                is Result.Failure -> {
                    val campo = (r.error as? pe.lactocolus.mobile.core.common.AppError.Validacion)?.campo
                    _registro.value = _registro.value.copy(guardando = false, error = r.error.aMensaje(), errorCampo = campo)
                }
            }
        }
    }

    fun consumirEntregaCreada() { _registro.value = _registro.value.copy(entregaCreada = null) }

    fun corregir(entregaId: String, jornadaId: String, litros: Double, obs: String?, motivo: String) = viewModelScope.launch {
        when (val r = corregirEntregaUC(entregaId, jornadaId, litros, obs, motivo)) {
            is Result.Success -> _mensaje.value = "Entrega corregida"
            is Result.Failure -> _mensaje.value = r.error.aMensaje()
        }
    }
}
