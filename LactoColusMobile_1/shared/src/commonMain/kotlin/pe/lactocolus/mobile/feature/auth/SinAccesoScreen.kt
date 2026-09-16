package pe.lactocolus.mobile.feature.auth

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ReportProblem
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import org.koin.compose.viewmodel.koinViewModel
import pe.lactocolus.mobile.core.designsystem.LactoColusTheme
import pe.lactocolus.mobile.core.designsystem.Space
import pe.lactocolus.mobile.core.designsystem.components.BotonPrincipal
import pe.lactocolus.mobile.domain.model.Usuario

/**
 * Shown instead of the authenticated shell when [Usuario.rol] resolved to `SIN_ACCESO` — an
 * account whose Spatie roles don't include recolector/calidad/productor (e.g. only
 * administrador or contador). Never falls back into one of the three mobile flows.
 */
@Composable
fun SinAccesoScreen(usuario: Usuario, vm: SinAccesoViewModel = koinViewModel()) {
    SinAccesoContenido(correo = usuario.correo, onVolverAlLogin = vm::volverAlLogin)
}

@Composable
private fun SinAccesoContenido(correo: String, onVolverAlLogin: () -> Unit) {
    Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
        Column(
            Modifier.fillMaxSize().padding(Space.xl),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center,
        ) {
            Icon(
                Icons.Filled.ReportProblem,
                contentDescription = null,
                modifier = Modifier.size(48.dp),
                tint = MaterialTheme.colorScheme.error,
            )
            Spacer(Modifier.height(Space.md))
            Text(
                "Esta cuenta no tiene acceso a la app móvil",
                style = MaterialTheme.typography.titleMedium,
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(Space.xs))
            Text(
                "$correo inició sesión correctamente, pero su rol no está habilitado para " +
                    "recolección, calidad ni productor en esta app. Usa el panel web de " +
                    "LactoColus para administrar tu cuenta.",
                style = MaterialTheme.typography.bodyMedium,
                textAlign = TextAlign.Center,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.height(Space.lg))
            BotonPrincipal("Volver al inicio de sesión", onVolverAlLogin)
        }
    }
}

@Preview
@Composable
private fun SinAccesoPreview() {
    LactoColusTheme {
        SinAccesoContenido(correo = "contador@lactocolus.test", onVolverAlLogin = {})
    }
}
