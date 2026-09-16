package pe.lactocolus.mobile

import pe.lactocolus.mobile.core.navigation.Destino
import pe.lactocolus.mobile.core.navigation.Navegador
import pe.lactocolus.mobile.core.navigation.barraDeRol
import pe.lactocolus.mobile.core.navigation.inicioDeRol
import pe.lactocolus.mobile.core.navigation.sincronizacionDeRol
import pe.lactocolus.mobile.domain.model.Rol
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertTrue

/** Navegación por rol (spec §4 y §10). */
class NavegacionRolTest {

    @Test
    fun cada_rol_arranca_en_su_inicio() {
        assertEquals(Destino.RecolectorInicio, inicioDeRol(Rol.RECOLECTOR))
        assertEquals(Destino.CalidadInicio, inicioDeRol(Rol.CALIDAD))
        assertEquals(Destino.ProductorInicio, inicioDeRol(Rol.PRODUCTOR))
    }

    @Test
    fun barra_del_recolector_no_incluye_sincronizacion() {
        // El recolector no administra la cola: la pantalla sigue existiendo (Destino.RecolectorSync),
        // pero se llega desde Perfil o la tira de conexión, no desde la barra inferior.
        val etiquetas = barraDeRol(Rol.RECOLECTOR).map { it.etiqueta }
        assertEquals(listOf("Inicio", "Ruta", "Historial", "Perfil"), etiquetas)
    }

    @Test
    fun sincronizacion_de_rol_sigue_alcanzable_fuera_de_la_barra() {
        assertEquals(Destino.RecolectorSync, sincronizacionDeRol(Rol.RECOLECTOR))
        assertEquals(Destino.CalidadSync, sincronizacionDeRol(Rol.CALIDAD))
        assertEquals(null, sincronizacionDeRol(Rol.PRODUCTOR))
    }

    @Test
    fun barra_del_productor_tiene_cuatro_pestanas() {
        val etiquetas = barraDeRol(Rol.PRODUCTOR).map { it.etiqueta }
        assertEquals(listOf("Inicio", "Entregas", "Calidad", "Más"), etiquetas)
    }

    @Test
    fun navegador_apila_y_vuelve() {
        val nav = Navegador(inicioDeRol(Rol.RECOLECTOR))
        assertFalse(nav.puedeVolver)
        nav.navegar(Destino.RecolectorRutas)
        nav.navegar(Destino.RecolectorRuta("r1"))
        assertTrue(nav.puedeVolver)
        assertTrue(nav.atras())
        assertEquals(Destino.RecolectorRutas, nav.actual)
    }

    @Test
    fun seleccionar_pestana_limpia_la_pila() {
        val nav = Navegador(inicioDeRol(Rol.PRODUCTOR))
        nav.navegar(Destino.ProductorRanking)
        nav.navegar(Destino.ProductorComunicados)
        nav.seleccionarPestana(Destino.ProductorInicio)
        assertEquals(Destino.ProductorInicio, nav.actual)
        assertFalse(nav.puedeVolver)
    }
}
