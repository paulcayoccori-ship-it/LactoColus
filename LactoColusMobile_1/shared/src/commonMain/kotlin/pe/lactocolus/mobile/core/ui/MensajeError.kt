package pe.lactocolus.mobile.core.ui

import pe.lactocolus.mobile.core.common.AppError

/** Traduce el error de dominio a copy en español para la UI. */
fun AppError.aMensaje(): String = when (this) {
    is AppError.Validacion -> mensaje
    is AppError.Conflicto -> mensaje
    is AppError.Sincronizacion -> mensaje
    is AppError.Almacenamiento -> "No se pudo guardar: $mensaje"
    AppError.NoEncontrado -> "No se encontró el registro"
    AppError.SesionVencida -> "La sesión expiró, vuelve a iniciar sesión"
}
