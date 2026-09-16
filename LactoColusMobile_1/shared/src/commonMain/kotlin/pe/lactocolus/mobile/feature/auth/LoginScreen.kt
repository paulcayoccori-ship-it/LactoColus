package pe.lactocolus.mobile.feature.auth

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import org.koin.compose.viewmodel.koinViewModel
import pe.lactocolus.mobile.core.designsystem.LactoColusTheme
import pe.lactocolus.mobile.core.designsystem.Space
import pe.lactocolus.mobile.core.designsystem.components.Alerta
import pe.lactocolus.mobile.core.designsystem.components.BotonPrincipal
import pe.lactocolus.mobile.core.designsystem.components.CampoContrasena
import pe.lactocolus.mobile.core.designsystem.components.CampoTexto
import pe.lactocolus.mobile.core.designsystem.components.TonoAlerta

@Composable
fun LoginScreen(vm: AuthViewModel = koinViewModel()) {
    val estado by vm.estado.collectAsStateWithLifecycle()
    LoginContenido(
        correo = estado.correo,
        clave = estado.clave,
        cargando = estado.cargando,
        error = estado.error,
        onCorreo = vm::onCorreo,
        onClave = vm::onClave,
        onEntrar = vm::entrar,
    )
}

@Composable
private fun LoginContenido(
    correo: String,
    clave: String,
    cargando: Boolean,
    error: String?,
    onCorreo: (String) -> Unit,
    onClave: (String) -> Unit,
    onEntrar: () -> Unit,
) {
    Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
        Column(
            Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(Space.lg),
            verticalArrangement = Arrangement.spacedBy(Space.md),
        ) {
            Spacer(Modifier.height(Space.xl))
            Text("LactoColus", style = MaterialTheme.typography.displaySmall, color = MaterialTheme.colorScheme.primary)
            Text("Planta Láctea Colus · Puno", style = MaterialTheme.typography.bodyLarge, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Spacer(Modifier.height(Space.md))

            if (error != null) Alerta(error, TonoAlerta.CRITICA)

            CampoTexto(correo, onCorreo, "Correo", tipoTeclado = KeyboardType.Email)
            CampoContrasena(clave, onClave, "Contraseña")

            Spacer(Modifier.height(Space.sm))
            BotonPrincipal("Entrar", onEntrar, cargando = cargando, habilitado = correo.isNotBlank() && clave.isNotBlank())
        }
    }
}

@Preview
@Composable
private fun LoginPreviewClaro() {
    LactoColusTheme(pe.lactocolus.mobile.core.designsystem.TemaApp.CLARO) {
        LoginContenido("recolector@lactocolus.test", "", false, null, {}, {}, {})
    }
}

@Preview
@Composable
private fun LoginPreviewOscuro() {
    LactoColusTheme(pe.lactocolus.mobile.core.designsystem.TemaApp.OSCURO) {
        LoginContenido("", "", false, "Ingresa un correo válido", {}, {}, {})
    }
}
