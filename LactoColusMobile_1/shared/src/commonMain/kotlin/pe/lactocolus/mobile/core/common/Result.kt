package pe.lactocolus.mobile.core.common

/**
 * Single sealed result type used across every layer. Business and infrastructure
 * failures are represented as data, never as exceptions crossing a layer boundary.
 */
sealed interface Result<out T> {
    data class Success<T>(val value: T) : Result<T>
    data class Failure(val error: AppError) : Result<Nothing>
}

inline fun <T, R> Result<T>.map(transform: (T) -> R): Result<R> = when (this) {
    is Result.Success -> Result.Success(transform(value))
    is Result.Failure -> this
}

inline fun <T> Result<T>.onSuccess(block: (T) -> Unit): Result<T> {
    if (this is Result.Success) block(value)
    return this
}

inline fun <T> Result<T>.onFailure(block: (AppError) -> Unit): Result<T> {
    if (this is Result.Failure) block(error)
    return this
}

fun <T> Result<T>.getOrNull(): T? = (this as? Result.Success)?.value

/** Domain-level error taxonomy. Presentation maps these to Spanish copy. */
sealed interface AppError {
    /** Local validation failed before touching persistence. */
    data class Validacion(val mensaje: String, val campo: String? = null) : AppError

    /** The record or its parent could not be found locally. */
    data object NoEncontrado : AppError

    /** The operation is not allowed in the current state (e.g. jornada cerrada). */
    data class Conflicto(val mensaje: String) : AppError

    /** The session token is missing or expired. */
    data object SesionVencida : AppError

    /** Persistence itself failed. */
    data class Almacenamiento(val mensaje: String) : AppError

    /** Remote synchronisation rejected the record. */
    data class Sincronizacion(val mensaje: String) : AppError
}
