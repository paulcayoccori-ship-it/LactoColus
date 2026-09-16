package pe.lactocolus.mobile

import app.cash.sqldelight.driver.jdbc.sqlite.JdbcSqliteDriver
import pe.lactocolus.mobile.db.LactoColusDb
import kotlin.test.AfterTest
import kotlin.test.BeforeTest
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertNull
import kotlin.test.assertTrue

/**
 * DAOs de SQLDelight contra una base en memoria (spec §10). Cubre entrega, cola_sync y
 * analisis, y verifica que la cola de sincronización se procesa en orden y por estado.
 */
class DaoEnMemoriaTest {

    private lateinit var driver: JdbcSqliteDriver
    private lateinit var db: LactoColusDb

    @BeforeTest
    fun preparar() {
        driver = JdbcSqliteDriver(JdbcSqliteDriver.IN_MEMORY)
        LactoColusDb.Schema.create(driver)
        db = LactoColusDb(driver)
    }

    @AfterTest
    fun cerrar() {
        driver.close()
    }

    @Test
    fun inserta_y_lee_una_entrega() {
        val a = db.acopioQueries
        a.insertJornada("j1", null, null, "r1", "primera_vuelta", 1, "2026-09-10", "abierta", null, 0.0, 0, 0, null, "PENDIENTE", "j1")
        a.insertEntrega("e1", null, "j1", "p1", 12.5, 0, 100, null, null, null, null, "PENDIENTE", null, 0, "e1")
        a.actualizarTotalesJornada("j1")

        val entregas = a.entregasDeJornada("j1").executeAsList()
        assertEquals(1, entregas.size)
        assertEquals(12.5, entregas.first().litros)

        val jornada = a.jornadaPorId("j1").executeAsOne()
        assertEquals(12.5, jornada.total_litros)
        assertEquals(1L, jornada.total_entregas)
    }

    @Test
    fun cola_sync_se_lee_en_orden_de_creacion_y_por_estado() {
        val s = db.syncQueries
        s.encolar("c2", "ENTREGA", "e2", "e2", "{}", 200, 200)
        s.encolar("c1", "ENTREGA", "e1", "e1", "{}", 100, 100)

        val pendientes = s.pendientes().executeAsList()
        assertEquals(listOf("c1", "c2"), pendientes.map { it.id_local })
        assertEquals(2, s.contarPendientes().executeAsOne().toInt())

        s.marcarResultado("CREADO", null, 300, "c1")
        assertEquals(1, s.contarPendientes().executeAsOne().toInt())

        s.marcarResultado("RECHAZADO", "Litros no positivos", 300, "c2")
        val rechazado = s.porId("c2").executeAsOne()
        assertEquals("RECHAZADO", rechazado.estado)
        assertEquals("Litros no positivos", rechazado.ultimo_error)
        // un RECHAZADO vuelve a estar pendiente para reintento
        assertEquals(1, s.contarPendientes().executeAsOne().toInt())
    }

    @Test
    fun analisis_con_parametros_y_conteos() {
        val c = db.calidadQueries
        c.insertAnalisis("a1", null, null, "p1", 1000, null, "manual", "observado", 3.4, null, "PENDIENTE", null, 0, "a1")
        c.insertParametro("pa1", "a1", "agua_anadida", 3.4, "%", 0, null, null)

        assertEquals(1, c.analisisDeProductor("p1").executeAsList().size)
        assertEquals(1, c.contarObservados().executeAsOne().toInt())
        assertEquals(1, c.contarAnalisisSinSync().executeAsOne().toInt())
        assertEquals(1, c.parametrosDeAnalisis("a1").executeAsList().size)

        c.marcarAnalisisSync("CREADO", null, null, "uuid-remoto", "a1")
        assertEquals("CREADO", c.analisisPorId("a1").executeAsOne().estado_sync)
        assertEquals(0, c.contarAnalisisSinSync().executeAsOne().toInt())
    }

    @Test
    fun schema_declara_version_inicial() {
        assertTrue(LactoColusDb.Schema.version >= 1)
        assertNull(db.usuarioQueries.sesionActual().executeAsOneOrNull())
    }
}
