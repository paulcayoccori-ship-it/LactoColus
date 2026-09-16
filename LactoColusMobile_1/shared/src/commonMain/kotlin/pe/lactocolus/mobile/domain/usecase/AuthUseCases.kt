package pe.lactocolus.mobile.domain.usecase

import kotlinx.coroutines.flow.Flow
import pe.lactocolus.mobile.core.common.AppError
import pe.lactocolus.mobile.core.common.Result
import pe.lactocolus.mobile.domain.model.Usuario
import pe.lactocolus.mobile.domain.repository.AuthRepository

class ObservarSesion(private val repo: AuthRepository) {
    operator fun invoke(): Flow<Usuario?> = repo.sesion()
}

class IniciarSesion(private val repo: AuthRepository) {
    suspend operator fun invoke(correo: String, clave: String): Result<Usuario> {
        val c = correo.trim()
        if (c.isEmpty() || !c.contains("@")) {
            return Result.Failure(AppError.Validacion("Ingresa un correo válido", "correo"))
        }
        if (clave.length < 4) {
            return Result.Failure(AppError.Validacion("La contraseña es muy corta", "clave"))
        }
        return repo.iniciarSesion(c, clave)
    }
}

class CerrarSesion(private val repo: AuthRepository) {
    suspend operator fun invoke() = repo.cerrarSesion()
}

/** Borra catálogos, pendientes de sync y sesión local. Deja el dispositivo como recién instalado. */
class BorrarDatosLocales(private val repo: AuthRepository) {
    suspend operator fun invoke() = repo.borrarDatosLocales()
}
