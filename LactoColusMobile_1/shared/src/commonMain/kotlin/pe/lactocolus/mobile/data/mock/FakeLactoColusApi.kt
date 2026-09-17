package pe.lactocolus.mobile.data.mock

import kotlinx.coroutines.delay
import pe.lactocolus.mobile.data.remote.AbrirJornadaRequest
import pe.lactocolus.mobile.data.remote.AnalisisSyncDto
import pe.lactocolus.mobile.data.remote.EntregaSyncDto
import pe.lactocolus.mobile.data.remote.ErrorRemotoException
import pe.lactocolus.mobile.data.remote.ItemResultado
import pe.lactocolus.mobile.data.remote.JornadaCalidadRemota
import pe.lactocolus.mobile.data.remote.JornadaCerradaRemota
import pe.lactocolus.mobile.data.remote.JornadaListadoRemota
import pe.lactocolus.mobile.data.remote.JornadaRemota
import pe.lactocolus.mobile.data.remote.JornadasPaginaRemota
import pe.lactocolus.mobile.data.remote.LactoColusApi
import pe.lactocolus.mobile.data.remote.LoginRequest
import pe.lactocolus.mobile.data.remote.LoginResponse
import pe.lactocolus.mobile.data.remote.MetaPaginacion
import pe.lactocolus.mobile.data.remote.ProductorEnRutaRemoto
import pe.lactocolus.mobile.data.remote.ProductorRemoto
import pe.lactocolus.mobile.data.remote.ProductoresPaginaRemota
import pe.lactocolus.mobile.data.remote.RegistroRemoto
import pe.lactocolus.mobile.data.remote.RutaRemota
import pe.lactocolus.mobile.data.remote.SincronizacionResponse
import pe.lactocolus.mobile.data.remote.SincronizarAnalisisRequest
import pe.lactocolus.mobile.data.remote.SincronizarEntregasRequest
import pe.lactocolus.mobile.data.remote.TrasladoRequest
import pe.lactocolus.mobile.core.common.randomUuid
import kotlin.random.Random

/**
 * Simulated backend (spec §9: no real API calls yet). Splits a batch into creados / repetidos
 * / rechazados the same way `/api/v1/acopios/sincronizar` does, so the sync-state mapping —
 * including REPETIDO and RECHAZADO — is exercised end to end against fake data.
 */
class FakeLactoColusApi : LactoColusApi {

    /** uuids the fake plant has "already seen" — a re-send of these comes back as repetido. */
    private val yaRegistrados = mutableSetOf<String>()

    /** The one open jornada the fake plant currently holds, if any — mirrors the real unique index. */
    private var jornadaAbiertaFake: JornadaListadoRemota? = null

    override suspend fun login(req: LoginRequest): LoginResponse {
        delay(400)
        return LoginResponse(token = "fake-token-${randomUuid()}")
    }

    override suspend fun listarProductores(pagina: Int, porPagina: Int): ProductoresPaginaRemota {
        delay(300)
        val datos = if (pagina == 1) listOf(ProductorRemoto(1, "DEMO-001", "Ana", "Quispe", true)) else emptyList()
        return ProductoresPaginaRemota(data = datos, meta = MetaPaginacion(pagina, ultimaPagina = 1, porPagina = porPagina, total = datos.size))
    }

    override suspend fun sincronizarEntregas(req: SincronizarEntregasRequest): SincronizacionResponse =
        procesar(req.entregas.map { it.uuidCliente }) { dto -> validarEntrega(req.entregas[dto]) }

    override suspend fun sincronizarAnalisis(req: SincronizarAnalisisRequest): SincronizacionResponse =
        procesar(req.analisis.map { it.uuidExterno }) { dto -> validarAnalisis(req.analisis[dto]) }

    override suspend fun listarRutas(): List<RutaRemota> {
        delay(200)
        return listOf(
            RutaRemota(
                id = 1, codigo = "DEMO-R01", nombre = "Ruta demo",
                productores = listOf(ProductorEnRutaRemoto(1, "DEMO-001", "Ana", "Quispe", 1)),
            ),
        )
    }

    override suspend fun listarJornadas(
        pagina: Int,
        porPagina: Int,
        estado: String?,
        rutaId: Long?,
        desde: String?,
        hasta: String?,
    ): JornadasPaginaRemota {
        delay(200)
        val abierta = jornadaAbiertaFake
        val datos = if (pagina == 1 && abierta != null && (estado == null || estado == abierta.estado)) listOf(abierta) else emptyList()
        return JornadasPaginaRemota(data = datos, meta = MetaPaginacion(pagina, ultimaPagina = 1, porPagina = porPagina, total = datos.size))
    }

    override suspend fun abrirJornada(req: AbrirJornadaRequest): JornadaRemota {
        delay(300)
        val existente = jornadaAbiertaFake
        if (existente != null && existente.rutaId == req.rutaId.toLongOrNull() && existente.fechaOperativa == req.fechaOperativa) {
            throw ErrorRemotoException(422)
        }
        val creada = JornadaListadoRemota(
            id = Random.nextLong(1000, 9999),
            uuidPublico = randomUuid(),
            rutaId = req.rutaId.toLongOrNull() ?: 0L,
            fechaOperativa = req.fechaOperativa,
            turno = req.turno,
            estado = "abierta",
            productoresAtendidos = 0,
            litros = "0.000",
        )
        jornadaAbiertaFake = creada
        return JornadaRemota(
            id = creada.id, uuidPublico = creada.uuidPublico, rutaId = creada.rutaId,
            fechaOperativa = creada.fechaOperativa, turno = creada.turno, estado = creada.estado,
        )
    }

    override suspend fun cerrarJornada(uuidPublico: String): JornadaCerradaRemota {
        delay(200)
        val abierta = jornadaAbiertaFake ?: throw ErrorRemotoException(422)
        if (abierta.uuidPublico != uuidPublico) throw ErrorRemotoException(422)
        jornadaAbiertaFake = null

        return JornadaCerradaRemota(
            id = abierta.id, uuidPublico = abierta.uuidPublico, rutaId = abierta.rutaId,
            estado = "cerrada", productoresAtendidos = 0, litros = "0.000",
        )
    }

    override suspend fun listarJornadasCalidad(
        fecha: String?,
        estado: String?,
        recolectorId: Long?,
    ): List<JornadaCalidadRemota> {
        delay(200)
        return emptyList()
    }

    override suspend fun solicitarTraslado(req: TrasladoRequest): RegistroRemoto {
        delay(300)
        return RegistroRemoto(id = Random.nextLong(1000, 9999), uuid = req.uuid, estado = "pendiente")
    }

    override suspend fun marcarLectura(uuid: String): RegistroRemoto {
        delay(150)
        return RegistroRemoto(id = 0, uuid = uuid, estado = "leida")
    }

    private fun validarEntrega(dto: EntregaSyncDto): String? =
        if (dto.litros <= 0.0) "Litros no positivos" else null

    private fun validarAnalisis(dto: AnalisisSyncDto): String? =
        if (dto.densidadMedida <= 0.0) "La densidad medida debe ser mayor que 0" else null

    private suspend fun procesar(uuids: List<String>, validar: (Int) -> String?): SincronizacionResponse {
        delay(600)
        val creados = mutableListOf<ItemResultado>()
        val repetidos = mutableListOf<ItemResultado>()
        val rechazados = mutableListOf<ItemResultado>()
        uuids.forEachIndexed { i, uuid ->
            val error = validar(i)
            when {
                error != null -> rechazados += ItemResultado(indice = i, errores = mapOf("general" to listOf(error)))
                !yaRegistrados.add(uuid) -> repetidos += ItemResultado(indice = i)
                else -> creados += ItemResultado(indice = i)
            }
        }
        return SincronizacionResponse(creados, repetidos, rechazados)
    }
}
