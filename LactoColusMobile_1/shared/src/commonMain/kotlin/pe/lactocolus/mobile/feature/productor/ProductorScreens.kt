package pe.lactocolus.mobile.feature.productor

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.tooling.preview.Preview
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import pe.lactocolus.mobile.core.designsystem.LactoColusTheme
import pe.lactocolus.mobile.core.designsystem.Space
import pe.lactocolus.mobile.core.designsystem.TemaApp
import pe.lactocolus.mobile.core.designsystem.components.Alerta
import pe.lactocolus.mobile.core.designsystem.components.BotonPrincipal
import pe.lactocolus.mobile.core.designsystem.components.CampoTexto
import pe.lactocolus.mobile.core.designsystem.components.EtiquetaResultadoAnalisis
import pe.lactocolus.mobile.core.designsystem.components.FilaIndicadores
import pe.lactocolus.mobile.core.designsystem.components.PantallaLista
import pe.lactocolus.mobile.core.designsystem.components.PantallaScroll
import pe.lactocolus.mobile.core.designsystem.components.SelectorSegmentado
import pe.lactocolus.mobile.core.designsystem.components.TarjetaBase
import pe.lactocolus.mobile.core.designsystem.components.TonoAlerta
import pe.lactocolus.mobile.core.navigation.Destino
import pe.lactocolus.mobile.core.ui.Formato
import pe.lactocolus.mobile.domain.model.Comunicado
import pe.lactocolus.mobile.domain.model.Liquidacion
import pe.lactocolus.mobile.domain.model.RankingEntry

// ---------- p_home ----------
@Composable
fun ProductorInicioScreen(vm: ProductorViewModel, onEntregas: () -> Unit, onLiquidaciones: () -> Unit) {
    val liquidaciones by vm.liquidaciones.collectAsStateWithLifecycle()
    val calidad by vm.miCalidad.collectAsStateWithLifecycle()
    val noLeidos by vm.noLeidos.collectAsStateWithLifecycle()
    PantallaScroll {
        val ultima = liquidaciones.firstOrNull()
        FilaIndicadores(
            (ultima?.let { Formato.litrosCorto(it.litros) } ?: "—") to "Litros última semana",
            (ultima?.let { Formato.soles(it.total) } ?: "—") to "Total",
        )
        if (noLeidos > 0) Alerta("Tienes $noLeidos comunicado(s) sin leer.", TonoAlerta.INFO)
        calidad.firstOrNull()?.let { a ->
            TarjetaBase {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                    Text("Mi último análisis", style = MaterialTheme.typography.titleMedium)
                    EtiquetaResultadoAnalisis(a.resultado)
                }
            }
        }
        Spacer(Modifier.height(Space.sm))
        BotonPrincipal("Ver mis entregas", onEntregas)
    }
}

// ---------- p_entregas ----------
@Composable
fun MisEntregasScreen(vm: ProductorViewModel) {
    val liquidaciones by vm.liquidaciones.collectAsStateWithLifecycle()
    PantallaLista(liquidaciones, cargando = false, tituloVacio = "Aún no hay semanas registradas") { l ->
        TarjetaBase {
            Text("Semana ${l.semana}", style = MaterialTheme.typography.titleMedium)
            Text("${l.desde} – ${l.hasta}", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Text("${Formato.litros(l.litros)} · ${Formato.soles(l.total)}", style = MaterialTheme.typography.bodyLarge)
        }
    }
}

// ---------- p_calidad ----------
@Composable
fun MiCalidadScreen(vm: ProductorViewModel) {
    val calidad by vm.miCalidad.collectAsStateWithLifecycle()
    PantallaLista(calidad, cargando = false, tituloVacio = "Todavía no te han tomado muestras") { a ->
        TarjetaBase {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Text(Formato.fecha(a.fecha), style = MaterialTheme.typography.titleMedium)
                EtiquetaResultadoAnalisis(a.resultado)
            }
            val explicacion = when {
                a.tieneAguaAnadida -> "Se detectó agua añadida. Conversa con el técnico de la planta."
                a.resultado.name == "CONFORME" -> "Tu leche cumple con los parámetros revisados. ¡Buen trabajo!"
                else -> "La muestra está en revisión técnica."
            }
            Text(explicacion, style = MaterialTheme.typography.bodyMedium)
        }
    }
}

// ---------- p_mas ----------
@Composable
fun MasScreen(onDestino: (Destino) -> Unit) {
    PantallaScroll {
        listOf(
            "Ranking" to Destino.ProductorRanking,
            "Comunicados" to Destino.ProductorComunicados,
            "Solicitar traslado" to Destino.ProductorTraslado,
            "Mis liquidaciones" to Destino.ProductorLiquidaciones,
            "Perfil" to Destino.Perfil,
        ).forEach { (etiqueta, destino) ->
            TarjetaBase(onClick = { onDestino(destino) }) {
                Text(etiqueta, style = MaterialTheme.typography.titleMedium)
            }
        }
    }
}

// ---------- p_ranking ----------
@Composable
fun RankingScreen(vm: ProductorViewModel) {
    val periodo by vm.periodo.collectAsStateWithLifecycle()
    val rutaFiltro by vm.rutaFiltro.collectAsStateWithLifecycle()
    val rutas by vm.rutasRanking.collectAsStateWithLifecycle()
    val entradas by remember(periodo, rutaFiltro) { vm.rankingFlow() }.collectAsStateWithLifecycle()

    Column(Modifier.fillMaxSize().padding(Space.md), verticalArrangement = Arrangement.spacedBy(Space.sm)) {
        SelectorSegmentado(
            listOf("diario" to "Diario", "semanal" to "Semanal", "mensual" to "Mensual"),
            periodo, vm::cambiarPeriodo,
        )
        if (rutas.isNotEmpty()) {
            SelectorSegmentado(
                listOf("" to "Todas") + rutas.map { it to it },
                rutaFiltro ?: "",
                { vm.cambiarRutaFiltro(it.ifBlank { null }) },
            )
        }
        Text("Solo se muestran nombre, ruta y puntuación.", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        PantallaLista(entradas, cargando = false, tituloVacio = "Sin ranking para este periodo") { e -> RankingFila(e) }
    }
}

@Composable
private fun RankingFila(e: RankingEntry) {
    TarjetaBase {
        Row(Modifier.fillMaxWidth(), verticalAlignment = androidx.compose.ui.Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.md)) {
            Text("${e.posicion}", style = MaterialTheme.typography.headlineSmall, color = MaterialTheme.colorScheme.primary)
            Column(Modifier.fillMaxWidth().padding(end = Space.md)) {
                Text(e.productor, style = MaterialTheme.typography.titleMedium)
                Text(e.ruta, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
        Text("Puntuación ${Formato.puntuacion(e.puntuacion)}", style = MaterialTheme.typography.labelLarge)
    }
}

// ---------- p_comunicados ----------
@Composable
fun ComunicadosScreen(vm: ProductorViewModel) {
    val comunicados by vm.comunicados.collectAsStateWithLifecycle()
    PantallaLista(comunicados, cargando = false, tituloVacio = "No tienes comunicados") { c ->
        TarjetaBase(onClick = { vm.marcarLeido(c.idLocal) }) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Text(c.titulo, style = MaterialTheme.typography.titleMedium.copy(fontWeight = if (c.leido) FontWeight.Normal else FontWeight.Bold))
                if (!c.leido) Text("●", color = MaterialTheme.colorScheme.primary)
            }
            Text(c.tipo.name.lowercase(), style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Text(c.cuerpo, style = MaterialTheme.typography.bodyMedium)
            Text(Formato.fecha(c.fecha), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

// ---------- p_traslado ----------
@Composable
fun SolicitarTrasladoScreen(vm: ProductorViewModel, onEnviado: () -> Unit) {
    val estado by vm.traslado.collectAsStateWithLifecycle()
    val solicitudes by vm.solicitudes.collectAsStateWithLifecycle()
    var confirmar by remember { mutableStateOf(false) }
    val origen = "Ruta Norte"

    LaunchedEffect(estado.enviado) { if (estado.enviado) { onEnviado(); vm.reiniciarTraslado() } }

    PantallaScroll {
        val enTramite = solicitudes.firstOrNull { it.estado.name == "PENDIENTE" || it.estado.name == "APROBADA" }
        if (enTramite != null) {
            Alerta("Ya tienes una solicitud ${enTramite.estado.name.lowercase()} hacia ${enTramite.rutaDestino}.", TonoAlerta.INFO)
        }
        Text("Ruta actual: $origen", style = MaterialTheme.typography.titleMedium)
        CampoTexto(estado.destino, vm::onDestino, "Ruta destino")
        CampoTexto(estado.fecha, vm::onFechaTraslado, "Fecha deseada (AAAA-MM-DD)", soporte = "Al menos ${vm.anticipacionDias} días de anticipación.")
        CampoTexto(estado.motivo, vm::onMotivo, "Motivo", lineasMax = 3)
        if (estado.error != null) Alerta(estado.error!!, TonoAlerta.CRITICA)
        Spacer(Modifier.height(Space.sm))
        BotonPrincipal("Revisar y enviar", { confirmar = true }, cargando = estado.enviando, habilitado = enTramite == null)
    }

    if (confirmar) {
        pe.lactocolus.mobile.core.designsystem.components.DialogoConfirmacion(
            "Confirmar solicitud",
            "Traslado de $origen a ${estado.destino}, deseado para ${estado.fecha}.\nMotivo: ${estado.motivo}",
            "Enviar solicitud",
            onConfirmar = { confirmar = false; vm.enviarTraslado(origen) },
            onCancelar = { confirmar = false },
        )
    }
}

// ---------- p_liquid ----------
@Composable
fun LiquidacionesScreen(vm: ProductorViewModel) {
    val liquidaciones by vm.liquidaciones.collectAsStateWithLifecycle()
    val detalle by vm.liquidacion.collectAsStateWithLifecycle()
    PantallaLista(liquidaciones, cargando = false, tituloVacio = "Sin liquidaciones") { l ->
        TarjetaBase(onClick = { vm.cargarLiquidacion(l.idLocal) }) { LiquidacionResumen(l, detalle?.idLocal == l.idLocal) }
    }
}

@Composable
private fun LiquidacionResumen(l: Liquidacion, expandida: Boolean) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
        Text("Semana ${l.semana}", style = MaterialTheme.typography.titleMedium)
        Text(if (l.pagada) "Pagada" else "Pendiente", style = MaterialTheme.typography.labelMedium, color = if (l.pagada) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.error)
    }
    Text("${Formato.litros(l.litros)} × ${Formato.soles(l.precioLitro)}", style = MaterialTheme.typography.bodyMedium)
    if (expandida) {
        HorizontalDivider(Modifier.padding(vertical = Space.xs))
        FilaComprobante("Bruto", l.litros * l.precioLitro)
        FilaComprobante("Bonos", l.bonos)
        FilaComprobante("Penalizaciones", -l.penalizaciones)
        FilaComprobante("Descuentos", -l.descuentos)
        HorizontalDivider(Modifier.padding(vertical = Space.xs))
        FilaComprobante("Total", l.total, destacado = true)
    }
}

@Composable
private fun FilaComprobante(etiqueta: String, monto: Double, destacado: Boolean = false) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
        Text(etiqueta, style = if (destacado) MaterialTheme.typography.titleMedium else MaterialTheme.typography.bodyMedium)
        Text(Formato.soles(monto), style = if (destacado) MaterialTheme.typography.titleMedium else MaterialTheme.typography.bodyMedium)
    }
}

// ---------- previews ----------
@Preview @Composable private fun PreviewRankingClaro() {
    LactoColusTheme(TemaApp.CLARO) {
        Column(Modifier.padding(Space.md)) {
            RankingFila(RankingEntry("semanal", "2026-09-07", 1, "Elena Apaza", "Ruta Norte", 92.4))
        }
    }
}
@Preview @Composable private fun PreviewLiquidacionOscuro() {
    LactoColusTheme(TemaApp.OSCURO) {
        Column(Modifier.padding(Space.md)) {
            TarjetaBase { LiquidacionResumen(Liquidacion("x", "2026-W37", "2026-09-07", "2026-09-13", 71.0, 1.8, 4.0, 3.5, 1.0, 127.3, false), true) }
        }
    }
}
