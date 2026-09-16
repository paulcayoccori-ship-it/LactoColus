package pe.lactocolus.mobile.core.designsystem.components

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material.icons.filled.CloudDone
import androidx.compose.material.icons.filled.CloudOff
import androidx.compose.material.icons.filled.History
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.LocalShipping
import androidx.compose.material.icons.filled.Menu
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Route
import androidx.compose.material.icons.filled.Science
import androidx.compose.material.icons.filled.Sync
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.material.icons.filled.Warning
import pe.lactocolus.mobile.core.designsystem.LactoTheme
import pe.lactocolus.mobile.core.designsystem.Space
import pe.lactocolus.mobile.core.navigation.ItemBarra
import pe.lactocolus.mobile.core.ui.ConnectionState
import pe.lactocolus.mobile.core.ui.EstadoConexionUi
import pe.lactocolus.mobile.core.ui.Formato
import pe.lactocolus.mobile.core.ui.SyncState

fun iconoPorNombre(nombre: String): ImageVector = when (nombre) {
    "home" -> Icons.Filled.Home
    "route" -> Icons.Filled.Route
    "sync" -> Icons.Filled.Sync
    "history" -> Icons.Filled.History
    "person" -> Icons.Filled.Person
    "add" -> Icons.Filled.Add
    "local_shipping" -> Icons.Filled.LocalShipping
    "science" -> Icons.Filled.Science
    "menu" -> Icons.Filled.Menu
    else -> Icons.Filled.Home
}

@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
@Composable
fun BarraSuperior(
    titulo: String,
    puedeVolver: Boolean,
    onVolver: () -> Unit,
    onNotificaciones: (() -> Unit)? = null,
    noLeidos: Int = 0,
) {
    TopAppBar(
        title = { Text(titulo, style = MaterialTheme.typography.titleLarge) },
        navigationIcon = {
            if (puedeVolver) {
                IconButton(onClick = onVolver) {
                    Icon(Icons.Filled.ArrowBack, contentDescription = "Volver")
                }
            }
        },
        actions = {
            if (onNotificaciones != null) {
                IconButton(onClick = onNotificaciones) {
                    BadgedBox(badge = { if (noLeidos > 0) Badge { Text("$noLeidos") } }) {
                        Icon(Icons.Filled.Notifications, contentDescription = "Notificaciones")
                    }
                }
            }
        },
        colors = TopAppBarDefaults.topAppBarColors(
            containerColor = MaterialTheme.colorScheme.primary,
            titleContentColor = MaterialTheme.colorScheme.onPrimary,
            navigationIconContentColor = MaterialTheme.colorScheme.onPrimary,
            actionIconContentColor = MaterialTheme.colorScheme.onPrimary,
        ),
    )
}

@Composable
fun BarraInferior(
    items: List<ItemBarra>,
    rutaActual: String,
    onSeleccion: (ItemBarra) -> Unit,
) {
    NavigationBar(containerColor = MaterialTheme.colorScheme.surface) {
        items.forEach { item ->
            NavigationBarItem(
                selected = rutaActual.startsWith(item.destino.ruta),
                onClick = { onSeleccion(item) },
                icon = { Icon(iconoPorNombre(item.icono), contentDescription = item.etiqueta) },
                label = { Text(item.etiqueta, style = MaterialTheme.typography.labelSmall, textAlign = TextAlign.Center, maxLines = 1) },
            )
        }
    }
}

/**
 * Tira de estado de conexión: siempre visible bajo la barra superior (spec §6). Si [onClick] no
 * es null (el rol tiene una pantalla de sincronización) queda tocable para llegar a ella — la
 * única forma de hacerlo para el recolector, que ya no tiene esa pestaña en la barra inferior.
 */
@Composable
fun TiraConexion(estado: EstadoConexionUi, ahoraMillis: Long, onClick: (() -> Unit)? = null) {
    val offline = estado.conexion == ConnectionState.SIN_CONEXION
    val (fondo, contenido, icono, texto) = when {
        estado.sync is SyncState.Sincronizando ->
            Cuatro(MaterialTheme.colorScheme.primaryContainer, MaterialTheme.colorScheme.onPrimaryContainer, Icons.Filled.Sync, "Sincronizando…")
        offline ->
            Cuatro(LactoTheme.semanticos.advertenciaContenedor, LactoTheme.semanticos.advertencia, Icons.Filled.CloudOff, "Sin conexión")
        estado.pendientes > 0 ->
            Cuatro(LactoTheme.semanticos.advertenciaContenedor, LactoTheme.semanticos.advertencia, Icons.Filled.Warning, "${estado.pendientes} registro(s) por sincronizar")
        estado.sync is SyncState.ErrorSincronizacion ->
            Cuatro(MaterialTheme.colorScheme.errorContainer, MaterialTheme.colorScheme.error, Icons.Filled.Warning, "Error al sincronizar")
        else ->
            Cuatro(LactoTheme.semanticos.exitoContenedor, LactoTheme.semanticos.exito, Icons.Filled.CloudDone, "Todo sincronizado")
    }
    val modificadorTira = if (onClick != null) {
        Modifier.fillMaxWidth().clickable(onClick = onClick)
    } else {
        Modifier.fillMaxWidth()
    }
    Surface(color = fondo, contentColor = contenido, modifier = modificadorTira) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = Space.md, vertical = 6.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Space.sm),
        ) {
            Icon(icono, contentDescription = null, modifier = Modifier.size(16.dp))
            Text(texto, style = MaterialTheme.typography.labelMedium, modifier = Modifier.weight(1f))
            Text(
                "Últ. sinc.: ${Formato.relativo(estado.ultimaSincronizacionMillis, ahoraMillis)}",
                style = MaterialTheme.typography.labelSmall,
            )
        }
    }
}

private data class Cuatro(
    val fondo: androidx.compose.ui.graphics.Color,
    val contenido: androidx.compose.ui.graphics.Color,
    val icono: ImageVector,
    val texto: String,
)
