package pe.lactocolus.mobile.feature.calidad

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
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.tooling.preview.Preview
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import pe.lactocolus.mobile.core.designsystem.LactoColusTheme
import pe.lactocolus.mobile.core.designsystem.Space
import pe.lactocolus.mobile.core.designsystem.TemaApp
import pe.lactocolus.mobile.core.designsystem.components.Alerta
import pe.lactocolus.mobile.core.designsystem.components.BotonPrincipal
import pe.lactocolus.mobile.core.designsystem.components.CampoNumerico
import pe.lactocolus.mobile.core.designsystem.components.CampoTexto
import pe.lactocolus.mobile.core.designsystem.components.EtiquetaResultadoAnalisis
import pe.lactocolus.mobile.core.designsystem.components.FilaIndicadores
import pe.lactocolus.mobile.core.designsystem.components.PantallaLista
import pe.lactocolus.mobile.core.designsystem.components.PantallaScroll
import pe.lactocolus.mobile.core.designsystem.components.TarjetaBase
import pe.lactocolus.mobile.core.designsystem.components.TarjetaProductor
import pe.lactocolus.mobile.core.designsystem.components.TonoAlerta
import pe.lactocolus.mobile.core.ui.Formato
import pe.lactocolus.mobile.domain.model.Analisis
import pe.lactocolus.mobile.domain.usecase.ParametroCalidad
import pe.lactocolus.mobile.domain.usecase.SeccionAnalisis

// ---------- c_home ----------
@Composable
fun CalidadInicioScreen(vm: CalidadViewModel, onNuevo: () -> Unit, onAnalisis: (String) -> Unit) {
    val resumen by vm.resumen.collectAsStateWithLifecycle()
    val historial by vm.historial.collectAsStateWithLifecycle()
    PantallaScroll {
        val r = resumen
        FilaIndicadores(
            "${r?.hoy ?: 0}" to "Hoy",
            "${r?.observados ?: 0}" to "Observados",
            "${r?.pendientes ?: 0}" to "Pendientes",
        )
        FilaIndicadores("${r?.sinSincronizar ?: 0}" to "Sin sincronizar")
        Spacer(Modifier.height(Space.xs))
        Text("Últimos análisis", style = MaterialTheme.typography.titleMedium)
        historial.take(4).forEach { AnalisisFila(it) { onAnalisis(it.idLocal) } }
        Spacer(Modifier.height(Space.sm))
        BotonPrincipal("Nuevo análisis", onNuevo)
    }
}

@Composable
private fun AnalisisFila(a: Analisis, onClick: () -> Unit) {
    TarjetaBase(onClick = onClick) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Column {
                Text(Formato.fechaHora(a.fecha), style = MaterialTheme.typography.titleMedium)
                if (a.tieneAguaAnadida) Text("Agua añadida ${Formato.porcentaje(a.aguaAnadida)}", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.error)
            }
            EtiquetaResultadoAnalisis(a.resultado)
        }
    }
}

// ---------- c_buscar ----------
@Composable
fun BuscarProductorScreen(vm: CalidadViewModel, onElegido: (id: String, nombre: String) -> Unit) {
    var q by rememberSaveable { mutableStateOf("") }
    val resultados by vm.buscar(q).collectAsStateWithLifecycle()
    Column(Modifier.fillMaxSize().padding(Space.md), verticalArrangement = Arrangement.spacedBy(Space.sm)) {
        CampoTexto(q, { q = it }, "Buscar productor por nombre o código")
        PantallaLista(resultados, cargando = false, tituloVacio = if (q.isBlank()) "Escribe para buscar" else "Sin coincidencias") { p ->
            val nombreCompleto = "${p.nombres} ${p.apellidos}".trim()
            TarjetaProductor(p.codigo, nombreCompleto, onClick = { onElegido(p.idLocal, nombreCompleto) })
        }
    }
}

// ---------- c_analisis ----------
@Composable
fun NuevoAnalisisScreen(vm: CalidadViewModel, productorId: String, nombre: String, onResultado: (String) -> Unit) {
    val estado by vm.nuevo.collectAsStateWithLifecycle()
    LaunchedEffect(productorId) { vm.preparar(productorId, nombre) }
    estado.analisisCreado?.let { a -> LaunchedEffect(a.idLocal) { onResultado(a.idLocal); vm.consumirCreado() } }

    PantallaScroll {
        Text("Muestra de ${estado.productorNombre}", style = MaterialTheme.typography.titleLarge)
        Text("Los rangos son controles de captura, no criterios de conformidad.", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        Text("* Obligatorio", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)

        SeccionAnalisis.entries.filter { it != SeccionAnalisis.MUESTRA }.forEach { seccion ->
            Spacer(Modifier.height(Space.sm))
            Text(seccion.titulo, style = MaterialTheme.typography.titleMedium)
            ParametroCalidad.entries.filter { it.seccion == seccion }.forEach { param ->
                CampoNumerico(
                    valor = estado.valores[param].orEmpty(),
                    onValor = { vm.onValor(param, it) },
                    etiqueta = if (param.requerido) "${param.etiqueta} *" else param.etiqueta,
                    unidad = param.unidad,
                    error = estado.erroresCampo[param],
                )
            }
        }
        val agua = estado.valores[ParametroCalidad.AGUA_ANADIDA]?.toDoubleOrNull() ?: 0.0
        if (agua > 0.0) Alerta("El equipo reporta agua añadida. La muestra quedará observada y se avisará de forma destacada.", TonoAlerta.CRITICA, "Agua añadida detectada")

        CampoTexto(estado.observaciones, vm::onObservaciones, "Observaciones", lineasMax = 3)
        if (estado.error != null) Alerta(estado.error!!, TonoAlerta.CRITICA)

        Spacer(Modifier.height(Space.sm))
        BotonPrincipal("Guardar análisis", vm::guardar, habilitado = estado.guardarHabilitado, cargando = estado.guardando)
    }
}

// ---------- c_resultado ----------
@Composable
fun ResultadoAnalisisScreen(vm: CalidadViewModel, analisisId: String, onCerrar: () -> Unit) {
    val detalle by vm.detalle.collectAsStateWithLifecycle()
    LaunchedEffect(analisisId) { vm.cargarDetalle(analisisId) }
    val a = detalle ?: return
    PantallaScroll {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text(Formato.fechaHora(a.fecha), style = MaterialTheme.typography.titleLarge)
            EtiquetaResultadoAnalisis(a.resultado)
        }
        if (a.tieneAguaAnadida) {
            Alerta("Muestra observada por agua añadida (${Formato.porcentaje(a.aguaAnadida)}). Avisar a la planta.", TonoAlerta.CRITICA, "Agua añadida")
        }
        Text("Parámetros medidos", style = MaterialTheme.typography.titleMedium)
        a.parametros.forEach { p ->
            TarjetaBase {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                    Text(p.clave.replace('_', ' ').replaceFirstChar { it.uppercase() }, style = MaterialTheme.typography.bodyLarge)
                    Text("${Formato.decimal(p.valor, 4)} ${p.unidad}", style = MaterialTheme.typography.bodyLarge)
                }
            }
        }
        Spacer(Modifier.height(Space.sm))
        BotonPrincipal("Volver al inicio", onCerrar)
    }
}

// ---------- c_hist (ligera) ----------
@Composable
fun HistorialCalidadScreen(vm: CalidadViewModel, onAnalisis: (String) -> Unit) {
    val historial by vm.historial.collectAsStateWithLifecycle()
    PantallaLista(historial, cargando = false, tituloVacio = "Aún no hay análisis") { a ->
        AnalisisFila(a) { onAnalisis(a.idLocal) }
    }
}

// ---------- previews ----------
@Preview @Composable private fun PreviewCalidadClaro() {
    LactoColusTheme(TemaApp.CLARO) {
        PantallaScroll { Alerta("El equipo reporta agua añadida.", TonoAlerta.CRITICA, "Agua añadida detectada") }
    }
}
@Preview @Composable private fun PreviewCalidadOscuro() {
    LactoColusTheme(TemaApp.OSCURO) {
        PantallaScroll { Alerta("El equipo reporta agua añadida.", TonoAlerta.CRITICA, "Agua añadida detectada") }
    }
}
