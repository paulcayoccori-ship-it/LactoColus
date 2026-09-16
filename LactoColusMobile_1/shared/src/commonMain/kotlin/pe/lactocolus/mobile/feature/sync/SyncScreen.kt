package pe.lactocolus.mobile.feature.sync

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.tooling.preview.Preview
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import pe.lactocolus.mobile.core.designsystem.LactoColusTheme
import pe.lactocolus.mobile.core.designsystem.Space
import pe.lactocolus.mobile.core.designsystem.TemaApp
import pe.lactocolus.mobile.core.designsystem.components.BotonPrincipal
import pe.lactocolus.mobile.core.designsystem.components.BotonSecundario
import pe.lactocolus.mobile.core.designsystem.components.EtiquetaSync
import pe.lactocolus.mobile.core.designsystem.components.PantallaLista
import pe.lactocolus.mobile.core.designsystem.components.TarjetaBase
import pe.lactocolus.mobile.core.ui.Formato
import pe.lactocolus.mobile.domain.model.ColaSyncItem
import pe.lactocolus.mobile.domain.model.EstadoSync

@Composable
fun SincronizacionScreen(vm: SyncViewModel, mostrarMensaje: (String) -> Unit) {
    val cola by vm.cola.collectAsStateWithLifecycle()
    val pendientes by vm.pendientes.collectAsStateWithLifecycle()
    val sincronizando by vm.sincronizando.collectAsStateWithLifecycle()
    val mensaje by vm.mensaje.collectAsStateWithLifecycle()

    LaunchedEffect(mensaje) { mensaje?.let { mostrarMensaje(it); vm.consumirMensaje() } }

    Column(Modifier.fillMaxSize().padding(Space.md), verticalArrangement = Arrangement.spacedBy(Space.sm)) {
        Text(
            if (pendientes == 0) "Todo está sincronizado" else "$pendientes registro(s) por enviar",
            style = MaterialTheme.typography.titleLarge,
        )
        BotonPrincipal("Sincronizar todo", vm::sincronizarTodo, cargando = sincronizando, habilitado = pendientes > 0)
        Spacer(Modifier.height(Space.xs))
        PantallaLista(cola, cargando = false, tituloVacio = "La cola de sincronización está vacía") { item ->
            ColaFila(item, onReintentar = { vm.reintentar(item.idLocal) })
        }
    }
}

@Composable
private fun ColaFila(item: ColaSyncItem, onReintentar: () -> Unit) {
    TarjetaBase {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Column {
                Text(item.tipoEntidad.name.lowercase().replaceFirstChar { it.uppercase() }, style = MaterialTheme.typography.titleMedium)
                Text("Intentos: ${item.intentos} · ${Formato.relativo(item.actualizadoEn, item.actualizadoEn + 1)}", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            EtiquetaSync(item.estado)
        }
        val err = (item.estado as? EstadoSync.Rechazado)?.mensaje ?: item.ultimoError
        if (err != null && item.estado is EstadoSync.Rechazado) {
            Text(err, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.error)
            BotonSecundario("Reintentar", onReintentar)
        }
    }
}

@Preview @Composable private fun PreviewSyncClaro() {
    LactoColusTheme(TemaApp.CLARO) {
        Column(Modifier.padding(Space.md)) {
            ColaFila(ColaSyncItem("x", pe.lactocolus.mobile.domain.model.TipoEntidadSync.ENTREGA, "e", "e", "{}", 2, "Litros no positivos", EstadoSync.Rechazado("Litros no positivos"), 0, 0), {})
        }
    }
}
@Preview @Composable private fun PreviewSyncOscuro() {
    LactoColusTheme(TemaApp.OSCURO) {
        Column(Modifier.padding(Space.md)) {
            ColaFila(ColaSyncItem("x", pe.lactocolus.mobile.domain.model.TipoEntidadSync.ANALISIS, "a", "a", "{}", 0, null, EstadoSync.Pendiente, 0, 0), {})
        }
    }
}
