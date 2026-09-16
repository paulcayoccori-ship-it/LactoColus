package pe.lactocolus.mobile.core.designsystem.components

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.Surface
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CloudOff
import androidx.compose.material.icons.filled.Inbox
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.WarningAmber
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import pe.lactocolus.mobile.core.designsystem.Corner
import pe.lactocolus.mobile.core.designsystem.Space

/** Estado con color + icono + texto (nunca solo color — accesibilidad, spec §8). */
@Composable
private fun EstadoCentro(
    icono: ImageVector,
    titulo: String,
    detalle: String?,
    accion: (@Composable () -> Unit)?,
) {
    Column(
        modifier = Modifier.fillMaxSize().padding(Space.xl),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Icon(icono, contentDescription = null, modifier = Modifier.size(48.dp), tint = MaterialTheme.colorScheme.onSurfaceVariant)
        Spacer(Modifier.height(Space.md))
        Text(titulo, style = MaterialTheme.typography.titleMedium, textAlign = TextAlign.Center)
        if (detalle != null) {
            Spacer(Modifier.height(Space.xs))
            Text(detalle, style = MaterialTheme.typography.bodyMedium, textAlign = TextAlign.Center, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        if (accion != null) {
            Spacer(Modifier.height(Space.lg))
            accion()
        }
    }
}

@Composable
fun EstadoVacio(titulo: String = "Sin resultados", detalle: String? = "Aún no hay información para mostrar.") =
    EstadoCentro(Icons.Filled.Inbox, titulo, detalle, null)

@Composable
fun EstadoError(mensaje: String = "Error del servidor", onReintentar: (() -> Unit)? = null) =
    EstadoCentro(Icons.Filled.WarningAmber, "Algo salió mal", mensaje, onReintentar?.let { { BotonSecundario("Reintentar", it) } })

@Composable
fun EstadoSinConexion(onReintentar: (() -> Unit)? = null) =
    EstadoCentro(
        Icons.Filled.CloudOff,
        "Sin conexión",
        "Trabajas sin conexión. Los datos guardados están seguros y se enviarán al reconectar.",
        onReintentar?.let { { BotonSecundario("Reintentar", it) } },
    )

@Composable
fun EstadoCargando(mensaje: String = "Cargando…") {
    Column(
        modifier = Modifier.fillMaxSize().padding(Space.xl),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        CircularProgressIndicator()
        Spacer(Modifier.height(Space.md))
        Text(mensaje, style = MaterialTheme.typography.bodyMedium)
    }
}

/** Placeholder con esqueleto para listas mientras cargan. */
@Composable
fun EsqueletoLista(filas: Int = 5) {
    Column(Modifier.fillMaxWidth().padding(Space.md), verticalArrangement = Arrangement.spacedBy(Space.sm)) {
        repeat(filas) {
            Surface(
                color = MaterialTheme.colorScheme.surfaceVariant,
                shape = RoundedCornerShape(Corner.md),
                modifier = Modifier.fillMaxWidth().height(64.dp),
            ) {}
        }
    }
}
