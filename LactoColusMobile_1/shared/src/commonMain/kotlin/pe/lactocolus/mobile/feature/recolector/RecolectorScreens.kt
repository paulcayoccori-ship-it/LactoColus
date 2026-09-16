package pe.lactocolus.mobile.feature.recolector

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import pe.lactocolus.mobile.core.designsystem.LactoColusTheme
import pe.lactocolus.mobile.core.designsystem.Space
import pe.lactocolus.mobile.core.designsystem.TemaApp
import pe.lactocolus.mobile.core.designsystem.components.Alerta
import pe.lactocolus.mobile.core.designsystem.components.BotonPrincipal
import pe.lactocolus.mobile.core.designsystem.components.BotonSecundario
import pe.lactocolus.mobile.core.designsystem.components.CampoTexto
import pe.lactocolus.mobile.core.designsystem.components.DialogoConfirmacion
import pe.lactocolus.mobile.core.designsystem.components.EtiquetaJornada
import pe.lactocolus.mobile.core.designsystem.components.EtiquetaSync
import pe.lactocolus.mobile.core.designsystem.components.EstadoVacio
import pe.lactocolus.mobile.core.designsystem.components.FilaIndicadores
import pe.lactocolus.mobile.core.designsystem.components.PantallaLista
import pe.lactocolus.mobile.core.designsystem.components.PantallaScroll
import pe.lactocolus.mobile.core.designsystem.components.TarjetaBase
import pe.lactocolus.mobile.core.designsystem.components.TarjetaProductor
import pe.lactocolus.mobile.core.designsystem.components.TarjetaRuta
import pe.lactocolus.mobile.core.designsystem.components.TonoAlerta
import pe.lactocolus.mobile.core.ui.Formato
import pe.lactocolus.mobile.domain.model.Entrega
import pe.lactocolus.mobile.domain.model.Jornada
import pe.lactocolus.mobile.domain.model.Productor

// ---------- r_home ----------
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun RecolectorInicioScreen(
    vm: RecolectorViewModel,
    onAbrirRuta: (String) -> Unit,
    onIrARegistro: (String, String) -> Unit,
    onResumen: (String) -> Unit,
) {
    val home by vm.home.collectAsStateWithLifecycle()
    val refrescando by vm.refrescando.collectAsStateWithLifecycle()
    val iniciando by vm.iniciandoJornada.collectAsStateWithLifecycle()
    LaunchedEffect(Unit) { vm.refrescarCatalogo() }
    if (home.cargando) return

    PullToRefreshBox(isRefreshing = refrescando, onRefresh = vm::refrescarCatalogo) {
        PantallaScroll {
            val j = home.jornada
            when {
                j != null -> {
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Text("Jornada ${Formato.fecha(j.abiertaEn)}", style = MaterialTheme.typography.titleLarge)
                        EtiquetaJornada(j.estado)
                    }
                    FilaIndicadores(
                        "${home.atendidos}" to "Atendidos",
                        "${home.pendientesPorVisitar}" to "Por visitar",
                        Formato.litrosCorto(j.totalLitros) to "Litros",
                    )
                    Spacer(Modifier.height(Space.xs))
                    BotonPrincipal("Ver productores de la ruta", { onAbrirRuta(j.rutaId) })
                    Text("Últimas entregas", style = MaterialTheme.typography.titleMedium)
                    home.entregas.take(4).forEach { EntregaFila(it) }
                    Spacer(Modifier.height(Space.sm))
                    BotonPrincipal("Ver resumen de jornada", { onResumen(j.idLocal) })
                }
                home.rutas.isEmpty() -> {
                    EstadoVacio("Sin ruta asignada", "Todavía no tienes una ruta asignada. Contacta al administrador para empezar.")
                }
                else -> {
                    EstadoVacio("Sin jornada activa", "Inicia tu jornada del día para empezar a registrar entregas.")
                    Spacer(Modifier.height(Space.md))
                    BotonPrincipal("Iniciar jornada", vm::iniciarJornadaDelDia, cargando = iniciando)
                }
            }
        }
    }
}

@Composable
private fun EntregaFila(e: Entrega) {
    TarjetaBase {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Column {
                Text(if (e.noEntrego) "No entregó" else Formato.litros(e.litros), style = MaterialTheme.typography.titleMedium)
                Text(Formato.hora(e.recolectadaEn), style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            EtiquetaSync(e.estadoSync)
        }
    }
}

// ---------- r_rutas ----------
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun RecolectorRutasScreen(vm: RecolectorViewModel, onRuta: (String) -> Unit) {
    val rutas by vm.rutas.collectAsStateWithLifecycle()
    val jornadas by vm.jornadas.collectAsStateWithLifecycle()
    val refrescando by vm.refrescando.collectAsStateWithLifecycle()
    LaunchedEffect(Unit) { vm.refrescarCatalogo() }

    PullToRefreshBox(isRefreshing = refrescando, onRefresh = vm::refrescarCatalogo) {
        PantallaLista(rutas, cargando = false, tituloVacio = "No tienes rutas asignadas") { ruta ->
            val jornada = jornadas.firstOrNull { it.rutaId == ruta.idLocal }
            TarjetaRuta(
                codigo = ruta.codigo,
                nombre = ruta.nombre,
                turno = ruta.turno,
                progreso = jornada?.let { "Jornada ${it.estado.name.lowercase()} · ${it.totalEntregas}/${ruta.totalProductores}" }
                    ?: "${ruta.totalProductores} productores",
                onClick = { onRuta(ruta.idLocal) },
            )
        }
    }
}

// ---------- r_ruta ----------
@Composable
fun RecolectorRutaScreen(
    vm: RecolectorViewModel,
    rutaId: String,
    onRegistrar: (jornadaId: String, productorId: String) -> Unit,
) {
    val productores by remember(rutaId) { vm.productoresDeRuta(rutaId) }.collectAsStateWithLifecycle()
    val home by vm.home.collectAsStateWithLifecycle()
    var q by rememberSaveable { mutableStateOf("") }
    val jornadaId = home.jornada?.idLocal
    val entregasIds = home.entregas.map { it.productorId }.toSet()

    Column(Modifier.fillMaxSize().padding(Space.md), verticalArrangement = Arrangement.spacedBy(Space.sm)) {
        CampoTexto(q, { q = it }, "Buscar por nombre o código")
        if (jornadaId == null) {
            Alerta("Abre una jornada para registrar entregas en esta ruta.", TonoAlerta.ADVERTENCIA)
        }
        val filtrados = productores.filter {
            q.isBlank() || it.nombres.contains(q, true) || it.apellidos.contains(q, true) || it.codigo.contains(q, true)
        }
        PantallaLista(filtrados, cargando = false, tituloVacio = "Sin productores") { prod ->
            val atendido = prod.idLocal in entregasIds
            TarjetaProductor(
                codigo = prod.codigo,
                nombre = "${prod.nombres} ${prod.apellidos}".trim(),
                subtitulo = "Prom. ${Formato.litrosCorto(prod.promedioLitros)} L",
                trailing = { if (atendido) EtiquetaSyncMini() else BotonSecundario("Registrar", { jornadaId?.let { onRegistrar(it, prod.idLocal) } }) },
            )
        }
    }
}

@Composable private fun EtiquetaSyncMini() = Text("✓ Atendido", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.primary)

// ---------- r_reg ----------
@Composable
fun RegistrarEntregaScreen(
    vm: RecolectorViewModel,
    jornadaId: String,
    productorId: String,
    onConfirmado: (String) -> Unit,
) {
    val registro by vm.registro.collectAsStateWithLifecycle()
    val productor = registro.productor

    LaunchedEffect(jornadaId, productorId) { vm.prepararRegistroPorId(jornadaId, productorId) }

    registro.entregaCreada?.let { creada ->
        LaunchedEffect(creada.idLocal) { onConfirmado(creada.idLocal); vm.consumirEntregaCreada() }
    }

    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(Space.md), verticalArrangement = Arrangement.spacedBy(Space.md)) {
        Text(productor?.let { "${it.nombres} ${it.apellidos}".trim() }?.ifBlank { null } ?: "Productor", style = MaterialTheme.typography.headlineSmall)
        if ((productor?.promedioLitros ?: 0.0) > 0) {
            Text("Promedio del productor: ${Formato.litros(productor!!.promedioLitros)}", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }

        if (!registro.noEntrego) {
            CampoTexto(
                valor = registro.litros,
                onValor = vm::onLitros,
                etiqueta = "Litros",
                tipoTeclado = KeyboardType.Decimal,
                unidad = "L",
                error = if (registro.errorCampo == "litros") registro.error else null,
            )
            Row(horizontalArrangement = Arrangement.spacedBy(Space.sm)) {
                listOf(-0.5, +0.5).forEach { d -> BotonSecundario(if (d > 0) "+0,5" else "−0,5", { vm.incrementarLitros(d) }) }
                listOf(5.0, 10.0, 15.0).forEach { v -> BotonSecundario("$v", { vm.onLitros(v.toString()) }) }
            }
        }
        BotonSecundario(if (registro.noEntrego) "Registrar litros" else "Marcar «No entregó»", { vm.onNoEntrego(!registro.noEntrego) })

        CampoTexto(
            registro.observacion, vm::onObservacion, "Observación",
            lineasMax = 3,
            error = if (registro.errorCampo == "observacion") registro.error else null,
            soporte = "Obligatoria si el valor difiere mucho del promedio.",
        )
        if (registro.error != null && registro.errorCampo == null) Alerta(registro.error!!, TonoAlerta.CRITICA)

        Spacer(Modifier.height(Space.sm))
        BotonPrincipal(
            if (registro.noEntrego) "Confirmar «No entregó»" else "Guardar entrega",
            vm::guardarEntrega,
            cargando = registro.guardando,
        )
    }
}

// ---------- r_conf ----------
@Composable
fun ConfirmacionScreen(entregaId: String, onSeguir: () -> Unit) {
    PantallaScroll {
        Spacer(Modifier.height(Space.xl))
        Text("Entrega registrada", style = MaterialTheme.typography.headlineMedium, color = MaterialTheme.colorScheme.primary)
        Alerta("La entrega se guardó en el dispositivo y se enviará a la planta al sincronizar.", TonoAlerta.INFO)
        Spacer(Modifier.height(Space.sm))
        BotonPrincipal("Seguir con la ruta", onSeguir)
    }
}

// ---------- r_resumen ----------
@Composable
fun ResumenJornadaScreen(vm: RecolectorViewModel, jornadaId: String, onCerrada: () -> Unit) {
    val entregas by vm.entregasDeJornada(jornadaId).collectAsStateWithLifecycle()
    val jornadas by vm.jornadas.collectAsStateWithLifecycle()
    val jornada = jornadas.firstOrNull { it.idLocal == jornadaId }
    var confirmar by remember { mutableStateOf(false) }
    val mensaje by vm.mensaje.collectAsStateWithLifecycle()

    LaunchedEffect(mensaje) { if (mensaje == "Jornada cerrada") { onCerrada(); vm.consumirMensaje() } }

    PantallaScroll {
        FilaIndicadores(
            "${entregas.count { !it.noEntrego }}" to "Entregas",
            "${entregas.count { it.noEntrego }}" to "No entregó",
            Formato.litrosCorto(entregas.filter { !it.noEntrego }.sumOf { it.litros ?: 0.0 }) to "Litros",
        )
        entregas.forEach { EntregaFila(it) }
        Spacer(Modifier.height(Space.md))
        if (jornada?.estado?.name == "ABIERTA") {
            BotonPrincipal("Cerrar jornada", { confirmar = true })
        } else {
            Text("Jornada cerrada. Las correcciones requieren autorización del jefe de producción.", style = MaterialTheme.typography.bodyMedium)
        }
    }

    if (confirmar) {
        DialogoConfirmacion(
            "Cerrar jornada",
            "Después de cerrar ya no podrás corregir entregas sin autorización. Puedes cerrar sin conexión.",
            "Cerrar jornada",
            onConfirmar = { confirmar = false; vm.cerrarJornada(jornadaId) },
            onCancelar = { confirmar = false },
        )
    }
}

// ---------- previews ----------
@Preview @Composable private fun PreviewInicioClaro() {
    LactoColusTheme(TemaApp.CLARO) { PantallaScroll { FilaIndicadores("4" to "Atendidos", "2" to "Por visitar", "48" to "Litros") } }
}
@Preview @Composable private fun PreviewInicioOscuro() {
    LactoColusTheme(TemaApp.OSCURO) { PantallaScroll { FilaIndicadores("4" to "Atendidos", "2" to "Por visitar", "48" to "Litros") } }
}
