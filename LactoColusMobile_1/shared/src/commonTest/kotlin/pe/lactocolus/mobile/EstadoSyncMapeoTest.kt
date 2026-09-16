package pe.lactocolus.mobile

import pe.lactocolus.mobile.domain.model.EstadoSync
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue

/** Mapeo de estados de sincronización, incluidos REPETIDO y RECHAZADO (spec §10). */
class EstadoSyncMapeoTest {

    @Test
    fun mapea_todas_las_claves_de_bd() {
        assertEquals(EstadoSync.Pendiente, EstadoSync.fromDb("PENDIENTE", null))
        assertEquals(EstadoSync.Enviando, EstadoSync.fromDb("ENVIANDO", null))
        assertEquals(EstadoSync.Creado, EstadoSync.fromDb("CREADO", null))
        assertEquals(EstadoSync.Repetido, EstadoSync.fromDb("REPETIDO", null))
    }

    @Test
    fun repetido_significa_ya_existia_en_la_planta() {
        val e = EstadoSync.fromDb("REPETIDO", "Ya existía en la planta")
        assertEquals(EstadoSync.Repetido, e)
        assertEquals("REPETIDO", e.clave)
    }

    @Test
    fun rechazado_conserva_el_mensaje_del_servidor() {
        val e = EstadoSync.fromDb("RECHAZADO", "Litros no positivos")
        assertTrue(e is EstadoSync.Rechazado)
        assertEquals("Litros no positivos", e.mensaje)
        assertEquals("RECHAZADO", e.clave)
    }

    @Test
    fun rechazado_sin_mensaje_usa_texto_por_defecto() {
        val e = EstadoSync.fromDb("RECHAZADO", null)
        assertTrue(e is EstadoSync.Rechazado)
        assertEquals("Rechazado por el servidor", e.mensaje)
    }

    @Test
    fun clave_desconocida_cae_en_pendiente() {
        assertEquals(EstadoSync.Pendiente, EstadoSync.fromDb("LO_QUE_SEA", null))
    }
}
