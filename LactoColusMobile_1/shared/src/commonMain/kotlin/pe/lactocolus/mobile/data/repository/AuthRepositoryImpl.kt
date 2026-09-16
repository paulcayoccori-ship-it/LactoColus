package pe.lactocolus.mobile.data.repository

import app.cash.sqldelight.coroutines.asFlow
import app.cash.sqldelight.coroutines.mapToOneOrNull
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.withContext
import pe.lactocolus.mobile.core.common.AppError
import pe.lactocolus.mobile.core.common.DispatcherProvider
import pe.lactocolus.mobile.core.common.Reloj
import pe.lactocolus.mobile.core.common.Result
import pe.lactocolus.mobile.core.common.SeedConfig
import pe.lactocolus.mobile.core.common.randomUuid
import pe.lactocolus.mobile.data.local.toDomain
import pe.lactocolus.mobile.data.remote.CredencialesInvalidasException
import pe.lactocolus.mobile.data.remote.ErrorRemotoException
import pe.lactocolus.mobile.data.remote.LactoColusApi
import pe.lactocolus.mobile.data.remote.LoginRequest
import pe.lactocolus.mobile.data.remote.LoginResponse
import pe.lactocolus.mobile.db.LactoColusDb
import pe.lactocolus.mobile.domain.model.Rol
import pe.lactocolus.mobile.domain.model.Usuario
import pe.lactocolus.mobile.domain.repository.AuthRepository
import pe.lactocolus.mobile.domain.repository.SeedRepository

class AuthRepositoryImpl(
    private val db: LactoColusDb,
    private val api: LactoColusApi,
    private val dispatchers: DispatcherProvider,
    private val reloj: Reloj,
    private val seed: SeedRepository,
) : AuthRepository {

    private val q get() = db.usuarioQueries

    override fun sesion(): Flow<Usuario?> =
        q.sesionActual().asFlow().mapToOneOrNull(dispatchers.io).map { it?.toDomain() }

    override suspend fun sesionActual(): Usuario? = withContext(dispatchers.io) {
        q.sesionActual().executeAsOneOrNull()?.toDomain()
    }

    /**
     * Real login against `POST /api/v1/login`. The backend now returns `data.user.roles` (a
     * Spatie role-name array — an account can hold several at once), so the rol is resolved by
     * [Rol.resolverDesdeRoles] instead of the old email-prefix guess. Local seed data is gated by
     * [SeedConfig.SEMBRAR_DATOS_DEMO] (off by default now that the backend is real) and, when on,
     * only populated for rols the app actually has a flow for; an account resolved to
     * [Rol.SIN_ACCESO] gets a session (so it can be logged out from the "no access" screen) but
     * no mock catalogs.
     */
    override suspend fun iniciarSesion(correo: String, clave: String): Result<Usuario> =
        withContext(dispatchers.io) {
            val respuesta: LoginResponse = try {
                api.login(LoginRequest(email = correo, password = clave))
            } catch (e: CredencialesInvalidasException) {
                return@withContext Result.Failure(AppError.Validacion("Las credenciales no son válidas.", "clave"))
            } catch (e: ErrorRemotoException) {
                return@withContext Result.Failure(AppError.Sincronizacion("El servidor respondió con un error (${e.status})."))
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                return@withContext Result.Failure(AppError.Sincronizacion("No se pudo conectar con el servidor. Verifica tu conexión."))
            }

            val rol = Rol.resolverDesdeRoles(respuesta.user?.roles ?: emptyList())

            val id = randomUuid()
            q.borrarSesion()
            q.guardarSesion(
                id_local = id,
                id_remoto = respuesta.user?.id,
                nombre = respuesta.user?.name ?: nombreDemo(rol),
                correo = respuesta.user?.email ?: correo,
                rol = rol.name,
                token = respuesta.token,
                vigente_hasta = reloj.ahoraMillis() + 30L * 24 * 60 * 60 * 1000,
                creado_en = reloj.ahoraMillis(),
            )
            if (rol != Rol.SIN_ACCESO && SeedConfig.SEMBRAR_DATOS_DEMO) seed.sembrarSiVacio(rol)
            Result.Success(q.sesionActual().executeAsOne().toDomain())
        }

    override suspend fun cerrarSesion() = withContext(dispatchers.io) {
        q.borrarSesion()
        Unit
    }

    override suspend fun borrarDatosLocales() = withContext(dispatchers.io) {
        db.transaction {
            db.syncQueries.borrarCola()
            db.acopioQueries.borrarEntregas()
            db.acopioQueries.borrarJornadas()
            db.acopioQueries.borrarProductores()
            db.acopioQueries.borrarRutas()
            db.calidadQueries.borrarParametros()
            db.calidadQueries.borrarAnalisis()
            db.productorQueries.borrarComunicados()
            db.productorQueries.borrarLiquidaciones()
            db.productorQueries.borrarRanking()
            db.productorQueries.borrarTraslados()
            q.borrarSesion()
        }
    }

    private fun nombreDemo(rol: Rol) = when (rol) {
        Rol.RECOLECTOR -> "Aldo Mamani"
        Rol.CALIDAD -> "Rosa Quispe"
        Rol.PRODUCTOR -> "Julia Condori"
        Rol.SIN_ACCESO -> "Usuario"
    }
}
