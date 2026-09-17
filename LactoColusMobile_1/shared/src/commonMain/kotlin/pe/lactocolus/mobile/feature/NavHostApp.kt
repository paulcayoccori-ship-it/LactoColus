package pe.lactocolus.mobile.feature

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import org.koin.compose.viewmodel.koinViewModel
import pe.lactocolus.mobile.core.designsystem.Space
import pe.lactocolus.mobile.core.designsystem.TemaApp
import pe.lactocolus.mobile.core.designsystem.components.EstadoVacio
import pe.lactocolus.mobile.core.designsystem.components.PantallaLista
import pe.lactocolus.mobile.core.designsystem.components.PantallaScroll
import pe.lactocolus.mobile.core.designsystem.components.TarjetaBase
import pe.lactocolus.mobile.core.navigation.Destino
import pe.lactocolus.mobile.core.navigation.Navegador
import pe.lactocolus.mobile.core.navigation.sincronizacionDeRol
import pe.lactocolus.mobile.core.ui.Formato
import pe.lactocolus.mobile.domain.model.Rol
import pe.lactocolus.mobile.feature.calidad.CalidadInicioScreen
import pe.lactocolus.mobile.feature.calidad.CalidadViewModel
import pe.lactocolus.mobile.feature.calidad.EntregasDeJornadaCalidadScreen
import pe.lactocolus.mobile.feature.calidad.HistorialCalidadScreen
import pe.lactocolus.mobile.feature.calidad.JornadasCalidadScreen
import pe.lactocolus.mobile.feature.calidad.NuevoAnalisisScreen
import pe.lactocolus.mobile.feature.calidad.ResultadoAnalisisScreen
import pe.lactocolus.mobile.feature.perfil.PerfilScreen
import pe.lactocolus.mobile.feature.perfil.PerfilViewModel
import pe.lactocolus.mobile.feature.productor.ComunicadosScreen
import pe.lactocolus.mobile.feature.productor.LiquidacionesScreen
import pe.lactocolus.mobile.feature.productor.MasScreen
import pe.lactocolus.mobile.feature.productor.MiCalidadScreen
import pe.lactocolus.mobile.feature.productor.MisEntregasScreen
import pe.lactocolus.mobile.feature.productor.ProductorInicioScreen
import pe.lactocolus.mobile.feature.productor.ProductorViewModel
import pe.lactocolus.mobile.feature.productor.RankingScreen
import pe.lactocolus.mobile.feature.productor.SolicitarTrasladoScreen
import pe.lactocolus.mobile.feature.recolector.ConfirmacionScreen
import pe.lactocolus.mobile.feature.recolector.RecolectorInicioScreen
import pe.lactocolus.mobile.feature.recolector.RecolectorRutaScreen
import pe.lactocolus.mobile.feature.recolector.RecolectorRutasScreen
import pe.lactocolus.mobile.feature.recolector.RecolectorViewModel
import pe.lactocolus.mobile.feature.recolector.RegistrarEntregaScreen
import pe.lactocolus.mobile.feature.recolector.ResumenJornadaScreen
import pe.lactocolus.mobile.feature.sync.SincronizacionScreen
import pe.lactocolus.mobile.feature.sync.SyncViewModel

@Composable
fun NavHostApp(
    rol: Rol,
    navegador: Navegador,
    tema: TemaApp,
    onTema: (TemaApp) -> Unit,
    mostrarMensaje: (String) -> Unit,
) {
    val destino = navegador.actual

    when (rol) {
        Rol.RECOLECTOR -> {
            val vm: RecolectorViewModel = koinViewModel()
            RecolectorGrafo(vm, destino, navegador, mostrarMensaje)
        }
        Rol.CALIDAD -> {
            val vm: CalidadViewModel = koinViewModel()
            CalidadGrafo(vm, destino, navegador)
        }
        Rol.PRODUCTOR -> {
            val vm: ProductorViewModel = koinViewModel()
            ProductorGrafo(vm, destino, navegador)
        }
        // App.kt never renders NavHostApp for SIN_ACCESO (it shows SinAccesoScreen instead).
        Rol.SIN_ACCESO -> {}
    }

    // pantallas comunes
    when (destino) {
        Destino.Perfil -> {
            val vm: PerfilViewModel = koinViewModel()
            PerfilScreen(
                vm, tema, onTema,
                onIrASincronizacion = sincronizacionDeRol(rol)?.let { destinoSync -> { navegador.navegar(destinoSync) } },
            )
        }
        Destino.Notificaciones -> NotificacionesScreen()
        else -> {}
    }
}

@Composable
private fun RecolectorGrafo(vm: RecolectorViewModel, destino: Destino, nav: Navegador, mostrarMensaje: (String) -> Unit) {
    val mensaje by vm.mensaje.collectAsStateWithLifecycle()
    androidx.compose.runtime.LaunchedEffect(mensaje) { mensaje?.let { mostrarMensaje(it); vm.consumirMensaje() } }
    when (destino) {
        Destino.RecolectorInicio -> RecolectorInicioScreen(vm, { nav.navegar(Destino.RecolectorRuta(it)) }, { j, p -> nav.navegar(Destino.RecolectorRegistro(j, p)) }, { nav.navegar(Destino.RecolectorResumen(it)) })
        Destino.RecolectorRutas -> RecolectorRutasScreen(vm) { nav.navegar(Destino.RecolectorRuta(it)) }
        is Destino.RecolectorRuta -> RecolectorRutaScreen(vm, destino.rutaId) { j, p -> nav.navegar(Destino.RecolectorRegistro(j, p)) }
        is Destino.RecolectorRegistro -> RegistrarEntregaScreen(vm, destino.jornadaId, destino.productorId) { nav.navegar(Destino.RecolectorConfirmacion(it)) }
        is Destino.RecolectorConfirmacion -> ConfirmacionScreen(destino.entregaId) { nav.atras(); nav.atras() }
        is Destino.RecolectorResumen -> ResumenJornadaScreen(vm, destino.jornadaId) { nav.seleccionarPestana(Destino.RecolectorInicio) }
        Destino.RecolectorSync -> {
            val sync: SyncViewModel = koinViewModel()
            SincronizacionScreen(sync, mostrarMensaje)
        }
        Destino.RecolectorHistorial -> HistorialJornadasScreen(vm)
        else -> {}
    }
}

@Composable
private fun CalidadGrafo(vm: CalidadViewModel, destino: Destino, nav: Navegador) {
    when (destino) {
        Destino.CalidadInicio -> CalidadInicioScreen(vm, { nav.navegar(Destino.CalidadJornadas) }, { nav.navegar(Destino.CalidadResultado(it)) })
        Destino.CalidadJornadas -> JornadasCalidadScreen(vm) { idRemoto -> nav.navegar(Destino.CalidadJornada(idRemoto.toString())) }
        is Destino.CalidadJornada -> EntregasDeJornadaCalidadScreen(vm, destino.jornadaIdRemoto.toLongOrNull() ?: 0L) { productorIdRemoto, nombre, entregaId ->
            nav.navegar(Destino.CalidadAnalisis(productorIdRemoto.toString(), nombre, entregaId.toString()))
        }
        is Destino.CalidadAnalisis -> NuevoAnalisisScreen(vm, destino.productorIdRemoto.toLongOrNull() ?: 0L, destino.nombre, destino.entregaId.toLongOrNull() ?: 0L) {
            nav.navegar(Destino.CalidadResultado(it))
        }
        is Destino.CalidadResultado -> ResultadoAnalisisScreen(vm, destino.analisisId) { nav.seleccionarPestana(Destino.CalidadInicio) }
        Destino.CalidadHistorial -> HistorialCalidadScreen(vm) { nav.navegar(Destino.CalidadResultado(it)) }
        Destino.CalidadSync -> {
            val sync: SyncViewModel = koinViewModel()
            SincronizacionScreen(sync) {}
        }
        else -> {}
    }
}

@Composable
private fun ProductorGrafo(vm: ProductorViewModel, destino: Destino, nav: Navegador) {
    when (destino) {
        Destino.ProductorInicio -> ProductorInicioScreen(vm, { nav.seleccionarPestana(Destino.ProductorEntregas) }, { nav.navegar(Destino.ProductorLiquidaciones) })
        Destino.ProductorEntregas -> MisEntregasScreen(vm)
        Destino.ProductorCalidad -> MiCalidadScreen(vm)
        Destino.ProductorMas -> MasScreen { nav.navegar(it) }
        Destino.ProductorRanking -> RankingScreen(vm)
        Destino.ProductorComunicados -> ComunicadosScreen(vm)
        Destino.ProductorTraslado -> SolicitarTrasladoScreen(vm) { nav.atras() }
        Destino.ProductorLiquidaciones -> LiquidacionesScreen(vm)
        else -> {}
    }
}

@Composable
private fun HistorialJornadasScreen(vm: RecolectorViewModel) {
    val jornadas by vm.jornadas.collectAsStateWithLifecycle()
    PantallaLista(jornadas, cargando = false, tituloVacio = "Sin jornadas anteriores") { j ->
        TarjetaBase {
            Text("Jornada ${j.fechaOperativa}", style = MaterialTheme.typography.titleMedium)
            Text("${j.estado.name.lowercase()} · ${j.totalEntregas} entregas · ${Formato.litros(j.totalLitros)}", style = MaterialTheme.typography.bodyMedium)
        }
    }
}

@Composable
private fun NotificacionesScreen() {
    PantallaScroll {
        EstadoVacio("Sin notificaciones nuevas", "Aquí verás avisos de sincronización, comunicados y jornadas.")
    }
}
