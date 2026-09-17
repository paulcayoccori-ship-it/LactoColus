package pe.lactocolus.mobile.data.remote

import io.ktor.client.HttpClient
import io.ktor.client.call.body
import io.ktor.client.request.accept
import io.ktor.client.request.get
import io.ktor.client.request.header
import io.ktor.client.request.parameter
import io.ktor.client.request.post
import io.ktor.client.request.setBody
import io.ktor.http.ContentType
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpStatusCode
import io.ktor.http.contentType
import io.ktor.http.isSuccess
import kotlinx.serialization.Serializable
import pe.lactocolus.mobile.db.LactoColusDb

/** Thrown by [KtorLactoColusApi.login] on a 401 response — wrong email/password. */
class CredencialesInvalidasException : Exception("Las credenciales no son válidas.")

/** Thrown when there is no saved session token to authenticate an authenticated-only call. */
class SesionAusenteException : Exception("No hay una sesión activa.")

/** Thrown on any other non-2xx response (401/403 on authenticated calls included). */
class ErrorRemotoException(val status: Int) : Exception("El servidor respondió con un error ($status).")

@Serializable
private data class LoginEnvelope(val message: String, val data: LoginResponse? = null)

@Serializable
private data class RutasEnvelope(val data: List<RutaRemota> = emptyList())

@Serializable
private data class JornadaEnvelope(val data: JornadaRemota? = null)

@Serializable
private data class JornadaCerradaEnvelope(val data: JornadaCerradaRemota)

/** `GET /api/v1/calidad/jornadas` isn't paginated (`Collection::get()`, not `paginate()`) — no `meta`/`links`. */
@Serializable
private data class JornadasCalidadEnvelope(val data: List<JornadaCalidadRemota> = emptyList())

@Serializable
private data class SincronizacionEnvelope(val data: SincronizacionResponse)

/**
 * Real backend implementation of [LactoColusApi]. Login, the productores catalog, rutas,
 * jornadas (list + abrir), and sincronizar (entregas + analisis) talk to the real API;
 * traslado/lectura are intentionally unimplemented (see each body) — connecting them is
 * follow-up work.
 */
class KtorLactoColusApi(
    private val client: HttpClient,
    private val db: LactoColusDb,
    private val baseUrl: String = NetworkConfig.BASE_URL,
) : LactoColusApi {

    override suspend fun login(req: LoginRequest): LoginResponse {
        val response = client.post("$baseUrl/login") {
            contentType(ContentType.Application.Json)
            accept(ContentType.Application.Json)
            setBody(req)
        }
        if (response.status == HttpStatusCode.Unauthorized) {
            throw CredencialesInvalidasException()
        }
        if (!response.status.isSuccess()) {
            throw ErrorRemotoException(response.status.value)
        }
        return response.body<LoginEnvelope>().data ?: throw ErrorRemotoException(response.status.value)
    }

    override suspend fun listarProductores(pagina: Int, porPagina: Int): ProductoresPaginaRemota {
        val token = db.usuarioQueries.sesionActual().executeAsOneOrNull()?.token ?: throw SesionAusenteException()
        val response = client.get("$baseUrl/productores") {
            accept(ContentType.Application.Json)
            header(HttpHeaders.Authorization, "Bearer $token")
            parameter("page", pagina)
            parameter("per_page", porPagina)
        }
        if (!response.status.isSuccess()) {
            throw ErrorRemotoException(response.status.value)
        }
        return response.body()
    }

    override suspend fun listarRutas(): List<RutaRemota> {
        val token = db.usuarioQueries.sesionActual().executeAsOneOrNull()?.token ?: throw SesionAusenteException()
        val response = client.get("$baseUrl/acopios/rutas") {
            accept(ContentType.Application.Json)
            header(HttpHeaders.Authorization, "Bearer $token")
        }
        if (!response.status.isSuccess()) {
            throw ErrorRemotoException(response.status.value)
        }
        return response.body<RutasEnvelope>().data
    }

    override suspend fun listarJornadas(
        pagina: Int,
        porPagina: Int,
        estado: String?,
        rutaId: Long?,
        desde: String?,
        hasta: String?,
    ): JornadasPaginaRemota {
        val token = db.usuarioQueries.sesionActual().executeAsOneOrNull()?.token ?: throw SesionAusenteException()
        val response = client.get("$baseUrl/acopios/jornadas") {
            accept(ContentType.Application.Json)
            header(HttpHeaders.Authorization, "Bearer $token")
            parameter("page", pagina)
            parameter("per_page", porPagina)
            estado?.let { parameter("estado", it) }
            rutaId?.let { parameter("ruta_id", it) }
            desde?.let { parameter("desde", it) }
            hasta?.let { parameter("hasta", it) }
        }
        if (!response.status.isSuccess()) {
            throw ErrorRemotoException(response.status.value)
        }
        return response.body()
    }

    override suspend fun sincronizarEntregas(req: SincronizarEntregasRequest): SincronizacionResponse {
        val token = db.usuarioQueries.sesionActual().executeAsOneOrNull()?.token ?: throw SesionAusenteException()
        val response = client.post("$baseUrl/acopios/sincronizar") {
            contentType(ContentType.Application.Json)
            accept(ContentType.Application.Json)
            header(HttpHeaders.Authorization, "Bearer $token")
            setBody(req)
        }
        if (!response.status.isSuccess()) {
            throw ErrorRemotoException(response.status.value)
        }
        return response.body<SincronizacionEnvelope>().data
    }

    override suspend fun sincronizarAnalisis(req: SincronizarAnalisisRequest): SincronizacionResponse {
        val token = db.usuarioQueries.sesionActual().executeAsOneOrNull()?.token ?: throw SesionAusenteException()
        val response = client.post("$baseUrl/calidad/sincronizar") {
            contentType(ContentType.Application.Json)
            accept(ContentType.Application.Json)
            header(HttpHeaders.Authorization, "Bearer $token")
            setBody(req)
        }
        if (!response.status.isSuccess()) {
            throw ErrorRemotoException(response.status.value)
        }
        return response.body<SincronizacionEnvelope>().data
    }

    override suspend fun abrirJornada(req: AbrirJornadaRequest): JornadaRemota {
        val token = db.usuarioQueries.sesionActual().executeAsOneOrNull()?.token ?: throw SesionAusenteException()
        val response = client.post("$baseUrl/acopios/jornadas") {
            contentType(ContentType.Application.Json)
            accept(ContentType.Application.Json)
            header(HttpHeaders.Authorization, "Bearer $token")
            setBody(req)
        }
        if (!response.status.isSuccess()) {
            throw ErrorRemotoException(response.status.value)
        }
        return response.body<JornadaEnvelope>().data ?: throw ErrorRemotoException(response.status.value)
    }

    override suspend fun cerrarJornada(uuidPublico: String): JornadaCerradaRemota {
        val token = db.usuarioQueries.sesionActual().executeAsOneOrNull()?.token ?: throw SesionAusenteException()
        val response = client.post("$baseUrl/acopios/jornadas/$uuidPublico/cerrar") {
            accept(ContentType.Application.Json)
            header(HttpHeaders.Authorization, "Bearer $token")
        }
        if (!response.status.isSuccess()) {
            throw ErrorRemotoException(response.status.value)
        }
        return response.body<JornadaCerradaEnvelope>().data
    }

    override suspend fun listarJornadasCalidad(
        fecha: String?,
        estado: String?,
        recolectorId: Long?,
    ): List<JornadaCalidadRemota> {
        val token = db.usuarioQueries.sesionActual().executeAsOneOrNull()?.token ?: throw SesionAusenteException()
        val response = client.get("$baseUrl/calidad/jornadas") {
            accept(ContentType.Application.Json)
            header(HttpHeaders.Authorization, "Bearer $token")
            fecha?.let { parameter("fecha", it) }
            estado?.let { parameter("estado", it) }
            recolectorId?.let { parameter("recolector_id", it) }
        }
        if (!response.status.isSuccess()) {
            throw ErrorRemotoException(response.status.value)
        }
        return response.body<JornadasCalidadEnvelope>().data
    }

    override suspend fun solicitarTraslado(req: TrasladoRequest): RegistroRemoto =
        TODO("KtorLactoColusApi.solicitarTraslado: fuera de alcance de esta entrega (solo login)")

    override suspend fun marcarLectura(uuid: String): RegistroRemoto =
        TODO("KtorLactoColusApi.marcarLectura: fuera de alcance de esta entrega (solo login)")
}
