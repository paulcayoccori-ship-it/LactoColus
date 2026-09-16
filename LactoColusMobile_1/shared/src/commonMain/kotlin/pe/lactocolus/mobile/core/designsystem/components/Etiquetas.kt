package pe.lactocolus.mobile.core.designsystem.components

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.CloudDone
import androidx.compose.material.icons.filled.CloudUpload
import androidx.compose.material.icons.filled.ErrorOutline
import androidx.compose.material.icons.filled.HourglassEmpty
import androidx.compose.material.icons.filled.ReportProblem
import androidx.compose.material.icons.filled.Sync
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.unit.dp
import pe.lactocolus.mobile.core.designsystem.CodigoMono
import pe.lactocolus.mobile.core.designsystem.LactoTheme
import pe.lactocolus.mobile.domain.model.EstadoJornada
import pe.lactocolus.mobile.domain.model.EstadoSync
import pe.lactocolus.mobile.domain.model.ResultadoAnalisis

enum class TonoEtiqueta { EXITO, ADVERTENCIA, ERROR, NEUTRAL, INFO }

/** Etiqueta de estado: color + icono + texto juntos (accesibilidad, spec §8). */
@Composable
fun EtiquetaEstado(texto: String, tono: TonoEtiqueta, icono: ImageVector) {
    val (fondo, contenido) = colores(tono)
    Surface(color = fondo, contentColor = contenido, shape = RoundedCornerShape(999.dp)) {
        Row(
            Modifier.padding(horizontal = 10.dp, vertical = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(4.dp),
        ) {
            Icon(icono, contentDescription = null, modifier = Modifier.size(14.dp))
            Text(texto, style = MaterialTheme.typography.labelMedium)
        }
    }
}

@Composable
private fun colores(tono: TonoEtiqueta): Pair<Color, Color> {
    val cs = MaterialTheme.colorScheme
    val sem = LactoTheme.semanticos
    return when (tono) {
        TonoEtiqueta.EXITO -> sem.exitoContenedor to sem.exito
        TonoEtiqueta.ADVERTENCIA -> sem.advertenciaContenedor to sem.advertencia
        TonoEtiqueta.ERROR -> cs.errorContainer to cs.error
        TonoEtiqueta.INFO -> cs.primaryContainer to cs.onPrimaryContainer
        TonoEtiqueta.NEUTRAL -> cs.surfaceVariant to cs.onSurfaceVariant
    }
}

@Composable
fun EtiquetaSync(estado: EstadoSync) = when (estado) {
    EstadoSync.Pendiente -> EtiquetaEstado("Pendiente", TonoEtiqueta.ADVERTENCIA, Icons.Filled.HourglassEmpty)
    EstadoSync.Enviando -> EtiquetaEstado("Enviando", TonoEtiqueta.INFO, Icons.Filled.CloudUpload)
    EstadoSync.Creado -> EtiquetaEstado("Sincronizado", TonoEtiqueta.EXITO, Icons.Filled.CloudDone)
    EstadoSync.Repetido -> EtiquetaEstado("Ya registrado", TonoEtiqueta.NEUTRAL, Icons.Filled.CheckCircle)
    is EstadoSync.Rechazado -> EtiquetaEstado("Rechazado", TonoEtiqueta.ERROR, Icons.Filled.ErrorOutline)
}

@Composable
fun EtiquetaJornada(estado: EstadoJornada) = when (estado) {
    EstadoJornada.ABIERTA -> EtiquetaEstado("Abierta", TonoEtiqueta.EXITO, Icons.Filled.Sync)
    EstadoJornada.CERRADA -> EtiquetaEstado("Cerrada", TonoEtiqueta.NEUTRAL, Icons.Filled.CheckCircle)
    EstadoJornada.ANULADA -> EtiquetaEstado("Anulada", TonoEtiqueta.ERROR, Icons.Filled.ErrorOutline)
}

@Composable
fun EtiquetaResultadoAnalisis(r: ResultadoAnalisis) = when (r) {
    ResultadoAnalisis.CONFORME -> EtiquetaEstado("Conforme", TonoEtiqueta.EXITO, Icons.Filled.CheckCircle)
    ResultadoAnalisis.OBSERVADO -> EtiquetaEstado("Observado", TonoEtiqueta.ADVERTENCIA, Icons.Filled.ReportProblem)
    ResultadoAnalisis.PENDIENTE_REVISION -> EtiquetaEstado("Pendiente de revisión", TonoEtiqueta.NEUTRAL, Icons.Filled.HourglassEmpty)
}

/** Chip monoespaciado para códigos de productor / ruta. */
@Composable
fun ChipCodigo(codigo: String) {
    Surface(color = MaterialTheme.colorScheme.surfaceVariant, contentColor = MaterialTheme.colorScheme.onSurfaceVariant, shape = RoundedCornerShape(8.dp)) {
        Text(codigo, style = CodigoMono, modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp))
    }
}
