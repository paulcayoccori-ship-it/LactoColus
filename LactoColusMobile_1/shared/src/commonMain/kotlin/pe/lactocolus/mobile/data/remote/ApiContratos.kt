package pe.lactocolus.mobile.data.remote

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * Transport-agnostic API contracts. DTOs mirror `backend/routes/api.php` (`/api/v1/...`) and
 * the request/response shapes in `backend/docs`. NOT wired to a real HTTP client in this
 * delivery — the sync engine talks to [LactoColusApi] and a fake implementation returns
 * simulated results. Drop in a Ktor-backed implementation later without touching other layers.
 */
interface LactoColusApi {
    suspend fun login(req: LoginRequest): LoginResponse
    suspend fun listarProductores(pagina: Int, porPagina: Int): ProductoresPaginaRemota
    suspend fun listarRutas(): List<RutaRemota>
    suspend fun listarJornadas(
        pagina: Int,
        porPagina: Int,
        estado: String?,
        rutaId: Long?,
        desde: String?,
        hasta: String?,
    ): JornadasPaginaRemota
    suspend fun sincronizarEntregas(req: SincronizarEntregasRequest): SincronizacionResponse
    suspend fun sincronizarAnalisis(req: SincronizarAnalisisRequest): SincronizacionResponse
    suspend fun abrirJornada(req: AbrirJornadaRequest): JornadaRemota
    suspend fun solicitarTraslado(req: TrasladoRequest): RegistroRemoto
    suspend fun marcarLectura(uuid: String): RegistroRemoto
}

@Serializable
data class LoginRequest(val email: String, val password: String, @SerialName("device_name") val deviceName: String = "app-movil")

@Serializable
data class LoginResponse(
    val token: String,
    @SerialName("token_type") val tokenType: String = "Bearer",
    val user: UsuarioRemoto? = null,
)

@Serializable
data class UsuarioRemoto(val id: Long, val name: String, val email: String, val roles: List<String> = emptyList())

/**
 * `GET /api/v1/productores` response shape. Only the fields the local catalog actually stores
 * are declared here (`ignoreUnknownKeys` on the client drops dni/celular/email/direccion/
 * comunidad/timestamps) — see `Acopio.sq`'s `productor` table.
 */
@Serializable
data class ProductorRemoto(
    val id: Long,
    val codigo: String,
    val nombres: String,
    val apellidos: String,
    val estado: Boolean,
)

@Serializable
data class MetaPaginacion(
    @SerialName("current_page") val paginaActual: Int,
    @SerialName("last_page") val ultimaPagina: Int,
    @SerialName("per_page") val porPagina: Int,
    val total: Int,
)

@Serializable
data class ProductoresPaginaRemota(val data: List<ProductorRemoto>, val meta: MetaPaginacion)

/**
 * `GET /api/v1/acopios/rutas` response shape — only the routes assigned to the authenticated
 * recolector, unpaginated. `orden` is the visit order within the route (spec §2).
 */
@Serializable
data class ProductorEnRutaRemoto(val id: Long, val codigo: String, val nombres: String, val apellidos: String, val orden: Int)

@Serializable
data class RutaRemota(val id: Long, val codigo: String, val nombre: String, val productores: List<ProductorEnRutaRemoto> = emptyList())

/**
 * `GET /api/v1/acopios/jornadas` list-item shape — the summary shape the backend's `summary()`
 * returns (see `backend/app/Infrastructure/Acopios/EloquentAcopioRepository.php`).
 */
@Serializable
data class JornadaListadoRemota(
    val id: Long,
    @SerialName("uuid_publico") val uuidPublico: String,
    @SerialName("ruta_id") val rutaId: Long,
    @SerialName("fecha_operativa") val fechaOperativa: String,
    val turno: String,
    val estado: String,
)

@Serializable
data class JornadasPaginaRemota(val data: List<JornadaListadoRemota>, val meta: MetaPaginacion)

/**
 * `POST /api/v1/acopios/sincronizar` entrega item. `jornadaId`/`productorId` are the **remote**
 * numeric ids (`exists:jornadas_acopio,id` / `exists:productores,id` on the backend) — never the
 * local uuids the app uses internally; [pe.lactocolus.mobile.data.sync.SyncEngine] resolves them
 * right before building this DTO. `recolectadaAt` must be ISO-8601
 * ([pe.lactocolus.mobile.core.common.fechaHoraIso]), not the dd/MM/yyyy display format.
 */
@Serializable
data class EntregaSyncDto(
    @SerialName("uuid_cliente") val uuidCliente: String,
    @SerialName("jornada_id") val jornadaId: Long,
    @SerialName("productor_id") val productorId: Long,
    val litros: Double,
    @SerialName("recolectada_at") val recolectadaAt: String,
    val observacion: String = "",
)

@Serializable
data class SincronizarEntregasRequest(val entregas: List<EntregaSyncDto>)

/**
 * `POST /api/v1/calidad/sincronizar` item. `productorId` is the **remote** numeric id
 * (`exists:productores,id` on the backend) — [pe.lactocolus.mobile.data.sync.SyncEngine]
 * resolves it from the local uuid right before building this DTO, same as [EntregaSyncDto].
 * `muestraAt` must be ISO-8601 ([pe.lactocolus.mobile.core.common.fechaHoraIso]), never a
 * future instant (the backend rejects it), and never the dd/MM/yyyy display format. The nine
 * `val` fields below (through [aguaAnadida]) are all required by the backend — do not default
 * them to `0.0` if a value is missing; that fabricates a false measurement. Never add
 * `ruta_id`/`responsable_id`/`estado`/`limites_aplicados`: the backend computes those and
 * rejects the whole item if they're present.
 */
@Serializable
data class AnalisisSyncDto(
    @SerialName("uuid_externo") val uuidExterno: String,
    @SerialName("productor_id") val productorId: Long,
    @SerialName("muestra_at") val muestraAt: String,
    val fuente: String,
    val grasa: Double,
    val proteina: Double,
    val lactosa: Double,
    @SerialName("densidad_medida") val densidadMedida: Double,
    val temperatura: Double,
    @SerialName("solidos_no_grasos") val solidosNoGrasos: Double,
    val ph: Double,
    val acidez: Double,
    @SerialName("agua_anadida") val aguaAnadida: Double,
    @SerialName("densidad_corregida") val densidadCorregida: Double? = null,
    @SerialName("solidos_totales") val solidosTotales: Double? = null,
    val equipo: String? = null,
    val observaciones: String? = null,
)

@Serializable
data class SincronizarAnalisisRequest(val analisis: List<AnalisisSyncDto>)

/**
 * `errores` mirrors Laravel's validator error bag (`$exception->errors()`): a map of field name
 * to its list of messages — e.g. `{"litros": ["Los litros deben ser mayores que cero."]}` — not
 * a flat list.
 */
@Serializable
data class ItemResultado(
    val indice: Int,
    @SerialName("uuid_publico") val uuidPublico: String? = null,
    val errores: Map<String, List<String>> = emptyMap(),
)

@Serializable
data class SincronizacionResponse(
    val creados: List<ItemResultado> = emptyList(),
    val repetidos: List<ItemResultado> = emptyList(),
    val rechazados: List<ItemResultado> = emptyList(),
)

@Serializable
data class AbrirJornadaRequest(
    @SerialName("ruta_id") val rutaId: String,
    @SerialName("fecha_operativa") val fechaOperativa: String,
    val turno: String,
    val observaciones: String? = null,
)

/** `POST /api/v1/acopios/jornadas` response shape (the backend's `detail()`, superset of `summary()`). */
@Serializable
data class JornadaRemota(
    val id: Long,
    @SerialName("uuid_publico") val uuidPublico: String,
    @SerialName("ruta_id") val rutaId: Long,
    @SerialName("fecha_operativa") val fechaOperativa: String,
    val turno: String,
    val estado: String,
)

@Serializable
data class TrasladoRequest(
    val uuid: String,
    @SerialName("ruta_solicitada_id") val rutaSolicitadaId: String,
    @SerialName("fecha_efectiva") val fechaEfectiva: String,
    val motivo: String,
)

@Serializable
data class RegistroRemoto(val id: Long, val uuid: String, val estado: String)
