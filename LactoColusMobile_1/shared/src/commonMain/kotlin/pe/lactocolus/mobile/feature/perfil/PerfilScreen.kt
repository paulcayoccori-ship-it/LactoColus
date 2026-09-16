package pe.lactocolus.mobile.feature.perfil

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import pe.lactocolus.mobile.core.designsystem.Space
import pe.lactocolus.mobile.core.designsystem.TemaApp
import pe.lactocolus.mobile.core.designsystem.components.BotonPrincipal
import pe.lactocolus.mobile.core.designsystem.components.BotonSecundario
import pe.lactocolus.mobile.core.designsystem.components.DialogoConfirmacion
import pe.lactocolus.mobile.core.designsystem.components.SelectorLista
import pe.lactocolus.mobile.core.designsystem.components.TarjetaBase
import pe.lactocolus.mobile.core.designsystem.components.PantallaScroll

@Composable
fun PerfilScreen(
    vm: PerfilViewModel,
    temaActual: TemaApp,
    onTema: (TemaApp) -> Unit,
    onIrASincronizacion: (() -> Unit)? = null,
) {
    val usuario by vm.usuario.collectAsStateWithLifecycle()
    val mostrarConfirmacionBorrado by vm.mostrarConfirmacionBorrado.collectAsStateWithLifecycle()
    PantallaScroll {
        TarjetaBase {
            Text(usuario?.nombre ?: "—", style = MaterialTheme.typography.titleLarge)
            Text(usuario?.correo ?: "", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Text("Rol: ${usuario?.rol?.name?.lowercase() ?: "—"}", style = MaterialTheme.typography.bodyMedium)
        }
        Text("Tema", style = MaterialTheme.typography.titleMedium)
        SelectorLista(
            opciones = listOf("Claro", "Oscuro", "Según el sistema"),
            seleccion = when (temaActual) { TemaApp.CLARO -> "Claro"; TemaApp.OSCURO -> "Oscuro"; TemaApp.SISTEMA -> "Según el sistema" },
            onSeleccion = { elegido ->
                onTema(when (elegido) { "Claro" -> TemaApp.CLARO; "Oscuro" -> TemaApp.OSCURO; else -> TemaApp.SISTEMA })
            },
        )
        Text("Versión ${vm.version}", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        Spacer(Modifier.height(Space.md))
        if (onIrASincronizacion != null) {
            BotonSecundario("Sincronización", onIrASincronizacion, modifier = Modifier.fillMaxWidth())
            Spacer(Modifier.height(Space.sm))
        }
        BotonPrincipal("Cerrar sesión", vm::cerrarSesion)
        Spacer(Modifier.height(Space.sm))
        BotonSecundario("Borrar datos locales y cerrar sesión", vm::solicitarBorrarDatos, modifier = Modifier.fillMaxWidth())
    }

    if (mostrarConfirmacionBorrado) {
        DialogoConfirmacion(
            titulo = "Borrar datos locales",
            mensaje = "Se borrarán todos los datos guardados en este teléfono (rutas, entregas, análisis, comunicados y más) y se cerrará la sesión. Los registros pendientes de sincronizar que aún no llegaron al servidor se perderán. Esta acción no se puede deshacer.",
            textoConfirmar = "Borrar y cerrar sesión",
            onConfirmar = vm::confirmarBorrarDatos,
            onCancelar = vm::cancelarBorrarDatos,
        )
    }
}
