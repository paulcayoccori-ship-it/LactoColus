package pe.lactocolus.mobile.core.designsystem.components

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyListScope
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import pe.lactocolus.mobile.core.designsystem.Space

/** Columna con scroll y padding estándar para pantallas de detalle/formulario. */
@Composable
fun PantallaScroll(
    modifier: Modifier = Modifier,
    content: @Composable ColumnScope.() -> Unit,
) {
    Column(
        modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(Space.md),
        verticalArrangement = Arrangement.spacedBy(Space.sm),
        content = content,
    )
}

/** Lista con estados vacío / cargando incorporados. */
@Composable
fun <T> PantallaLista(
    items: List<T>,
    cargando: Boolean,
    modifier: Modifier = Modifier,
    tituloVacio: String = "Sin resultados",
    detalleVacio: String? = null,
    fila: @Composable (T) -> Unit,
) {
    when {
        cargando && items.isEmpty() -> EsqueletoLista()
        items.isEmpty() -> EstadoVacio(tituloVacio, detalleVacio)
        else -> LazyColumn(
            modifier.fillMaxSize(),
            contentPadding = PaddingValues(Space.md),
            verticalArrangement = Arrangement.spacedBy(Space.sm),
        ) {
            items(items.size) { i -> fila(items[i]) }
        }
    }
}
