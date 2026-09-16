package pe.lactocolus.mobile.core.designsystem.components

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.ElevatedCard
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import pe.lactocolus.mobile.core.designsystem.Elevation
import pe.lactocolus.mobile.core.designsystem.NumeroGrande
import pe.lactocolus.mobile.core.designsystem.Space
import pe.lactocolus.mobile.core.designsystem.Touch

@Composable
fun TarjetaBase(
    modifier: Modifier = Modifier,
    onClick: (() -> Unit)? = null,
    content: @Composable () -> Unit,
) {
    val base = modifier.fillMaxWidth()
    if (onClick != null) {
        ElevatedCard(onClick = onClick, modifier = base.heightIn(min = Touch.min), elevation = CardDefaults.elevatedCardElevation(Elevation.level1)) {
            Column(Modifier.padding(Space.md)) { content() }
        }
    } else {
        ElevatedCard(modifier = base, elevation = CardDefaults.elevatedCardElevation(Elevation.level1)) {
            Column(Modifier.padding(Space.md)) { content() }
        }
    }
}

/** Tarjeta de indicador: cifra grande + etiqueta. */
@Composable
fun TarjetaIndicador(valor: String, etiqueta: String, modifier: Modifier = Modifier, detalle: String? = null) {
    TarjetaBase(modifier) {
        Text(valor, style = NumeroGrande, color = MaterialTheme.colorScheme.primary)
        Spacer(Modifier.width(0.dp))
        Text(etiqueta, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        if (detalle != null) Text(detalle, style = MaterialTheme.typography.bodyMedium)
    }
}

@Composable
fun FilaIndicadores(vararg indicadores: Pair<String, String>) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.sm)) {
        indicadores.forEach { (valor, etiqueta) ->
            TarjetaIndicador(valor, etiqueta, Modifier.weight(1f))
        }
    }
}

@Composable
fun TarjetaProductor(
    codigo: String,
    nombre: String,
    modifier: Modifier = Modifier,
    subtitulo: String? = null,
    trailing: @Composable (() -> Unit)? = null,
    onClick: (() -> Unit)? = null,
) {
    TarjetaBase(modifier, onClick) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.sm)) {
            Column(Modifier.weight(1f)) {
                Text(nombre, style = MaterialTheme.typography.titleMedium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Spacer(Modifier.width(0.dp))
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.xs)) {
                    ChipCodigo(codigo)
                    if (subtitulo != null) Text(subtitulo, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            }
            trailing?.invoke()
        }
    }
}

@Composable
fun TarjetaRuta(
    codigo: String,
    nombre: String,
    turno: String,
    progreso: String,
    modifier: Modifier = Modifier,
    onClick: (() -> Unit)? = null,
) {
    TarjetaBase(modifier, onClick) {
        Text(nombre, style = MaterialTheme.typography.titleMedium)
        Row(horizontalArrangement = Arrangement.spacedBy(Space.xs), verticalAlignment = Alignment.CenterVertically) {
            ChipCodigo(codigo)
            Text(turno.replace('_', ' '), style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        Spacer(Modifier.width(0.dp))
        Text(progreso, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.primary)
    }
}
