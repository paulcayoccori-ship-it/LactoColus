package pe.lactocolus.mobile.core.designsystem.components

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.LocalContentColor
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import pe.lactocolus.mobile.core.designsystem.Touch

/** Acción principal de pantalla: 64 dp de alto, ancho completo, siempre visible. */
@Composable
fun BotonPrincipal(
    texto: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    habilitado: Boolean = true,
    cargando: Boolean = false,
    contenidoInicial: (@Composable RowScope.() -> Unit)? = null,
) {
    Button(
        onClick = onClick,
        enabled = habilitado && !cargando,
        modifier = modifier.fillMaxWidth().heightIn(min = Touch.primaryAction),
        contentPadding = PaddingValues(horizontal = 20.dp, vertical = 12.dp),
    ) {
        if (cargando) {
            CircularProgressIndicator(strokeWidth = 2.dp, modifier = Modifier.size(20.dp), color = LocalContentColor.current)
        } else {
            contenidoInicial?.invoke(this)
            Text(texto, style = androidx.compose.material3.MaterialTheme.typography.labelLarge)
        }
    }
}

@Composable
fun BotonSecundario(
    texto: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    habilitado: Boolean = true,
) {
    OutlinedButton(
        onClick = onClick,
        enabled = habilitado,
        modifier = modifier.heightIn(min = Touch.min),
    ) {
        Text(texto, style = androidx.compose.material3.MaterialTheme.typography.labelLarge)
    }
}

@Composable
fun EspacioAccionPrincipal(content: @Composable () -> Unit) {
    Box(Modifier.fillMaxWidth().padding(top = 8.dp), contentAlignment = Alignment.Center) { content() }
}
