package pe.lactocolus.mobile

import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.test.runTest
import pe.lactocolus.mobile.core.common.AppError
import pe.lactocolus.mobile.core.common.Result
import pe.lactocolus.mobile.domain.model.Entrega
import pe.lactocolus.mobile.domain.model.EstadoJornada
import pe.lactocolus.mobile.domain.model.EstadoSync
import pe.lactocolus.mobile.domain.model.Jornada
import pe.lactocolus.mobile.domain.model.Productor
import pe.lactocolus.mobile.domain.repository.EntregaRepository
import pe.lactocolus.mobile.domain.repository.JornadaRepository
import pe.lactocolus.mobile.domain.repository.RutaRepository
import pe.lactocolus.mobile.domain.usecase.RegistrarEntrega
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue

private fun jornada(estado: EstadoJornada) = Jornada(
    "j1", null, null, "r1", "primera_vuelta", 1, "2026-09-10", estado, null, 0.0, 0, 0L, null, EstadoSync.Pendiente,
)

private class JornadaFake(var j: Jornada?) : JornadaRepository {
    override fun jornadas(): Flow<List<Jornada>> = MutableStateFlow(emptyList())
    override fun jornadaAbierta(): Flow<Jornada?> = MutableStateFlow(j)
    override suspend fun jornada(idLocal: String): Jornada? = j
    override suspend fun jornadaAbiertaAhora(): Jornada? = j
    override suspend fun abrirJornada(rutaId: String, turno: String, fechaOperativa: String, observaciones: String?) = Result.Failure(AppError.NoEncontrado)
    override suspend fun cerrarJornada(idLocal: String) = Result.Success(Unit)
    override suspend fun descargarJornadas() = Result.Success(Unit)
    override suspend fun iniciarJornadaHoy(rutaId: String, fechaOperativa: String) = Result.Failure(AppError.NoEncontrado)
}

private class RutaFake(private val promedio: Double) : RutaRepository {
    override fun rutas() = MutableStateFlow(emptyList<pe.lactocolus.mobile.domain.model.Ruta>())
    override suspend fun ruta(idLocal: String) = null
    override fun productoresDeRuta(rutaId: String) = MutableStateFlow(emptyList<Productor>())
    override fun buscarProductores(query: String) = MutableStateFlow(emptyList<Productor>())
    override suspend fun productor(idLocal: String) = Productor("p1", null, "P-001", "Julia", "Condori", "r1", 1, true, promedio)
    override suspend fun descargarProductores() = Result.Success(Unit)
    override suspend fun descargarRutas() = Result.Success(Unit)
}

private class EntregaFake(var existente: Entrega? = null) : EntregaRepository {
    val registradas = mutableListOf<Entrega>()
    override fun entregasDeJornada(jornadaId: String) = MutableStateFlow(registradas.toList())
    override suspend fun entregaDeProductor(jornadaId: String, productorId: String) = existente
    override suspend fun registrarEntrega(jornadaId: String, productorId: String, litros: Double?, noEntrego: Boolean, observacion: String?): Result<Entrega> {
        val e = Entrega("e${registradas.size}", null, jornadaId, productorId, litros, noEntrego, 0L, observacion, null, null, null, EstadoSync.Pendiente, 0)
        registradas += e
        return Result.Success(e)
    }
    override suspend fun corregirEntrega(idLocal: String, litros: Double, observacion: String?, motivo: String) = Result.Success(Unit)
}

class RegistrarEntregaTest {

    @Test
    fun registra_entrega_con_litros_validos() = runTest {
        val entregas = EntregaFake()
        val uc = RegistrarEntrega(entregas, JornadaFake(jornada(EstadoJornada.ABIERTA)), RutaFake(12.0))
        val r = uc("j1", "p1", 12.5, false, null)
        assertTrue(r is Result.Success)
        assertEquals(1, entregas.registradas.size)
        assertEquals(12.5, entregas.registradas.first().litros)
    }

    @Test
    fun no_entrego_no_es_litros_cero() = runTest {
        val entregas = EntregaFake()
        val uc = RegistrarEntrega(entregas, JornadaFake(jornada(EstadoJornada.ABIERTA)), RutaFake(12.0))
        val r = uc("j1", "p1", null, true, null)
        assertTrue(r is Result.Success)
        val e = entregas.registradas.first()
        assertTrue(e.noEntrego)
        assertEquals(null, e.litros)
    }

    @Test
    fun desviacion_grande_exige_observacion() = runTest {
        val entregas = EntregaFake()
        val uc = RegistrarEntrega(entregas, JornadaFake(jornada(EstadoJornada.ABIERTA)), RutaFake(10.0))
        val sinObs = uc("j1", "p1", 30.0, false, null)
        assertTrue(sinObs is Result.Failure)
        assertEquals("observacion", (sinObs.error as AppError.Validacion).campo)

        val conObs = uc("j1", "p1", 30.0, false, "Compró más vacas")
        assertTrue(conObs is Result.Success)
    }

    @Test
    fun jornada_cerrada_rechaza() = runTest {
        val uc = RegistrarEntrega(EntregaFake(), JornadaFake(jornada(EstadoJornada.CERRADA)), RutaFake(10.0))
        val r = uc("j1", "p1", 10.0, false, null)
        assertTrue(r is Result.Failure && r.error is AppError.Conflicto)
    }

    @Test
    fun entrega_duplicada_rechaza() = runTest {
        val existente = Entrega("e0", null, "j1", "p1", 5.0, false, 0L, null, null, null, null, EstadoSync.Pendiente, 0)
        val uc = RegistrarEntrega(EntregaFake(existente), JornadaFake(jornada(EstadoJornada.ABIERTA)), RutaFake(10.0))
        val r = uc("j1", "p1", 10.0, false, null)
        assertTrue(r is Result.Failure && r.error is AppError.Conflicto)
    }
}
