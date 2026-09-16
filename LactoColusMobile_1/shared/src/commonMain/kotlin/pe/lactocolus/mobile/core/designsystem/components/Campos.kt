package pe.lactocolus.mobile.core.designsystem.components

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import pe.lactocolus.mobile.core.designsystem.Space

@Composable
fun CampoTexto(
    valor: String,
    onValor: (String) -> Unit,
    etiqueta: String,
    modifier: Modifier = Modifier,
    error: String? = null,
    tipoTeclado: KeyboardType = KeyboardType.Text,
    unidad: String? = null,
    soporte: String? = null,
    lineasMax: Int = 1,
) {
    Column(modifier.fillMaxWidth()) {
        OutlinedTextField(
            value = valor,
            onValueChange = onValor,
            label = { Text(etiqueta) },
            isError = error != null,
            singleLine = lineasMax == 1,
            maxLines = lineasMax,
            trailingIcon = unidad?.let { { Text(it, style = MaterialTheme.typography.labelMedium) } },
            keyboardOptions = KeyboardOptions(keyboardType = tipoTeclado),
            modifier = Modifier.fillMaxWidth(),
        )
        val ayuda = error ?: soporte
        if (ayuda != null) {
            Text(
                ayuda,
                style = MaterialTheme.typography.labelMedium,
                color = if (error != null) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(start = 12.dp, top = 4.dp),
            )
        }
    }
}

/** Campo de contraseña: oculta el texto por defecto, con botón para mostrarlo/ocultarlo. */
@Composable
fun CampoContrasena(
    valor: String,
    onValor: (String) -> Unit,
    etiqueta: String,
    modifier: Modifier = Modifier,
    error: String? = null,
) {
    var visible by remember { mutableStateOf(false) }
    Column(modifier.fillMaxWidth()) {
        OutlinedTextField(
            value = valor,
            onValueChange = onValor,
            label = { Text(etiqueta) },
            isError = error != null,
            singleLine = true,
            visualTransformation = if (visible) VisualTransformation.None else PasswordVisualTransformation(),
            trailingIcon = {
                IconButton(onClick = { visible = !visible }) {
                    Icon(
                        if (visible) Icons.Filled.VisibilityOff else Icons.Filled.Visibility,
                        contentDescription = if (visible) "Ocultar contraseña" else "Mostrar contraseña",
                    )
                }
            },
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
            modifier = Modifier.fillMaxWidth(),
        )
        if (error != null) {
            Text(
                error,
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.error,
                modifier = Modifier.padding(start = 12.dp, top = 4.dp),
            )
        }
    }
}

/** Campo numérico con unidad y teclado decimal. */
@Composable
fun CampoNumerico(
    valor: String,
    onValor: (String) -> Unit,
    etiqueta: String,
    unidad: String,
    modifier: Modifier = Modifier,
    error: String? = null,
    soporte: String? = null,
) = CampoTexto(valor, onValor, etiqueta, modifier, error, KeyboardType.Decimal, unidad, soporte)

/** Selector segmentado (chips) para opciones cortas. */
@Composable
fun SelectorSegmentado(
    opciones: List<Pair<String, String>>, // id to etiqueta
    seleccion: String,
    onSeleccion: (String) -> Unit,
    modifier: Modifier = Modifier,
) {
    Row(modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.sm)) {
        opciones.forEach { (id, etiqueta) ->
            FilterChip(
                selected = id == seleccion,
                onClick = { onSeleccion(id) },
                label = { Text(etiqueta) },
            )
        }
    }
}

/** Selector de lista (radio) para opciones más largas. */
@Composable
fun SelectorLista(
    opciones: List<String>,
    seleccion: String?,
    onSeleccion: (String) -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(modifier.selectableGroup()) {
        opciones.forEach { opcion ->
            Row(
                Modifier.fillMaxWidth()
                    .selectable(selected = opcion == seleccion, onClick = { onSeleccion(opcion) }, role = Role.RadioButton)
                    .padding(vertical = Space.xs),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(Space.sm),
            ) {
                RadioButton(selected = opcion == seleccion, onClick = null)
                Text(opcion, style = MaterialTheme.typography.bodyLarge)
            }
        }
    }
}
