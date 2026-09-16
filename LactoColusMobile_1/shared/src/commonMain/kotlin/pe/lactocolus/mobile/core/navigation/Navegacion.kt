package pe.lactocolus.mobile.core.navigation

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshots.SnapshotStateList
import pe.lactocolus.mobile.domain.model.Rol

/**
 * Destinos de la app (las 28 pantallas del prototipo aprobado, INDICE en `LactoColus.html`).
 * `arg` transporta un id opcional (ruta, jornada, análisis, comunicado, liquidación).
 */
sealed class Destino(val ruta: String) {
    data object Splash : Destino("splash")
    data object Login : Destino("login")

    // Recolector
    data object RecolectorInicio : Destino("r_home")
    data object RecolectorRutas : Destino("r_rutas")
    data class RecolectorRuta(val rutaId: String) : Destino("r_ruta/$rutaId")
    data class RecolectorRegistro(val jornadaId: String, val productorId: String) : Destino("r_reg/$jornadaId/$productorId")
    data class RecolectorConfirmacion(val entregaId: String) : Destino("r_conf/$entregaId")
    data class RecolectorResumen(val jornadaId: String) : Destino("r_resumen/$jornadaId")
    data object RecolectorSync : Destino("r_sync")
    data object RecolectorHistorial : Destino("r_hist")

    // Calidad
    data object CalidadInicio : Destino("c_home")
    data object CalidadBuscar : Destino("c_buscar")
    data class CalidadAnalisis(val productorId: String, val nombre: String = "") : Destino("c_analisis/$productorId")
    data class CalidadResultado(val analisisId: String) : Destino("c_resultado/$analisisId")
    data object CalidadHistorial : Destino("c_hist")
    data object CalidadSync : Destino("c_sync")

    // Productor
    data object ProductorInicio : Destino("p_home")
    data object ProductorEntregas : Destino("p_entregas")
    data object ProductorCalidad : Destino("p_calidad")
    data object ProductorMas : Destino("p_mas")
    data object ProductorRanking : Destino("p_ranking")
    data object ProductorComunicados : Destino("p_comunicados")
    data object ProductorTraslado : Destino("p_traslado")
    data object ProductorLiquidaciones : Destino("p_liquid")

    // Comunes
    data object Notificaciones : Destino("notif")
    data object Perfil : Destino("perfil")
}

/**
 * Only called for a rol the app has a flow for (App.kt gates [Rol.SIN_ACCESO] into its own
 * screen before ever building a [Navegador]); the branch below is defensive, not a real path.
 */
fun inicioDeRol(rol: Rol): Destino = when (rol) {
    Rol.RECOLECTOR -> Destino.RecolectorInicio
    Rol.CALIDAD -> Destino.CalidadInicio
    Rol.PRODUCTOR -> Destino.ProductorInicio
    Rol.SIN_ACCESO -> Destino.Login
}

/** Ítem de la barra inferior: ruta + etiqueta + icono semántico (nombre). */
data class ItemBarra(val destino: Destino, val etiqueta: String, val icono: String)

/**
 * Pantalla de sincronización de cada rol, si tiene una — el recolector ya no la tiene en la
 * barra inferior (spec), pero sigue siendo alcanzable desde Perfil y desde la tira de conexión.
 */
fun sincronizacionDeRol(rol: Rol): Destino? = when (rol) {
    Rol.RECOLECTOR -> Destino.RecolectorSync
    Rol.CALIDAD -> Destino.CalidadSync
    Rol.PRODUCTOR -> null
    Rol.SIN_ACCESO -> null
}

fun barraDeRol(rol: Rol): List<ItemBarra> = when (rol) {
    // Sin pestaña de Sincronización: el recolector no administra la cola (spec). La pantalla
    // sigue existiendo — se llega desde Perfil o desde la tira de conexión (ver App.kt).
    Rol.RECOLECTOR -> listOf(
        ItemBarra(Destino.RecolectorInicio, "Inicio", "home"),
        ItemBarra(Destino.RecolectorRutas, "Ruta", "route"),
        ItemBarra(Destino.RecolectorHistorial, "Historial", "history"),
        ItemBarra(Destino.Perfil, "Perfil", "person"),
    )
    Rol.CALIDAD -> listOf(
        ItemBarra(Destino.CalidadInicio, "Inicio", "home"),
        ItemBarra(Destino.CalidadBuscar, "Nuevo análisis", "add"),
        ItemBarra(Destino.CalidadHistorial, "Historial", "history"),
        ItemBarra(Destino.CalidadSync, "Sincronización", "sync"),
        ItemBarra(Destino.Perfil, "Perfil", "person"),
    )
    Rol.PRODUCTOR -> listOf(
        ItemBarra(Destino.ProductorInicio, "Inicio", "home"),
        ItemBarra(Destino.ProductorEntregas, "Entregas", "local_shipping"),
        ItemBarra(Destino.ProductorCalidad, "Calidad", "science"),
        ItemBarra(Destino.ProductorMas, "Más", "menu"),
    )
    Rol.SIN_ACCESO -> emptyList()
}

/**
 * Navegador mínimo (pila en memoria). Se prefirió a una librería de navegación para no
 * añadir riesgo de resolución de dependencias en este toolchain nuevo; cubre pila, back,
 * reemplazo de raíz y selección de pestaña, y es testeable en commonTest sin Android.
 */
class Navegador(inicial: Destino) {
    private val _pila: SnapshotStateList<Destino> = mutableStateListOf(inicial)
    val pila: List<Destino> get() = _pila

    var actual by mutableStateOf(inicial)
        private set

    fun navegar(destino: Destino) {
        _pila.add(destino)
        actual = destino
    }

    /** Cambia de pestaña: limpia la pila hasta la raíz de la pestaña. */
    fun seleccionarPestana(destino: Destino) {
        _pila.clear()
        _pila.add(destino)
        actual = destino
    }

    fun atras(): Boolean {
        if (_pila.size <= 1) return false
        _pila.removeAt(_pila.lastIndex)
        actual = _pila.last()
        return true
    }

    fun reemplazarRaiz(destino: Destino) {
        _pila.clear()
        _pila.add(destino)
        actual = destino
    }

    val puedeVolver: Boolean get() = _pila.size > 1
}
