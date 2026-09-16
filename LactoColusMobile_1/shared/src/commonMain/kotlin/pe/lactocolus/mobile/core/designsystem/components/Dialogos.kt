package pe.lactocolus.mobile.core.designsystem.components

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.ReportProblem
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.unit.dp
import pe.lactocolus.mobile.core.designsystem.LactoTheme
import pe.lactocolus.mobile.core.designsystem.Space

enum class TonoAlerta { INFO, ADVERTENCIA, CRITICA }

/** Alerta en línea con color + icono + texto. La de agua añadida usa [TonoAlerta.CRITICA]. */
@Composable
fun Alerta(mensaje: String, tono: TonoAlerta = TonoAlerta.INFO, titulo: String? = null) {
    val cs = MaterialTheme.colorScheme
    val sem = LactoTheme.semanticos
    val (fondo, contenido, icono) = when (tono) {
        TonoAlerta.INFO -> Triple(cs.primaryContainer, cs.onPrimaryContainer, Icons.Filled.Info)
        TonoAlerta.ADVERTENCIA -> Triple(sem.advertenciaContenedor, sem.advertencia, Icons.Filled.ReportProblem)
        TonoAlerta.CRITICA -> Triple(cs.errorContainer, cs.error, Icons.Filled.ReportProblem)
    }
    Surface(color = fondo, contentColor = contenido, shape = RoundedCornerShape(Space.md), modifier = Modifier.padding(vertical = Space.xs)) {
        Row(Modifier.padding(Space.md), horizontalArrangement = Arrangement.spacedBy(Space.sm), verticalAlignment = Alignment.Top) {
            Icon(icono as ImageVector, contentDescription = null, modifier = Modifier.size(20.dp))
            androidx.compose.foundation.layout.Column {
                if (titulo != null) Text(titulo, style = MaterialTheme.typography.titleMedium)
                Text(mensaje, style = MaterialTheme.typography.bodyMedium)
            }
        }
    }
}

@Composable
fun DialogoConfirmacion(
    titulo: String,
    mensaje: String,
    textoConfirmar: String,
    onConfirmar: () -> Unit,
    onCancelar: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = onCancelar,
        title = { Text(titulo) },
        text = { Text(mensaje) },
        confirmButton = { TextButton(onClick = onConfirmar) { Text(textoConfirmar) } },
        dismissButton = { TextButton(onClick = onCancelar) { Text("Cancelar") } },
    )
}
