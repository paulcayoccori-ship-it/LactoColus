package pe.lactocolus.mobile

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.tooling.preview.Preview
import kotlinx.coroutines.launch
import org.koin.compose.KoinContext
import org.koin.compose.viewmodel.koinViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import pe.lactocolus.mobile.core.common.Reloj
import pe.lactocolus.mobile.core.designsystem.LactoColusTheme
import pe.lactocolus.mobile.core.designsystem.TemaApp
import pe.lactocolus.mobile.core.designsystem.components.BarraInferior
import pe.lactocolus.mobile.core.designsystem.components.BarraSuperior
import pe.lactocolus.mobile.core.designsystem.components.EstadoCargando
import pe.lactocolus.mobile.core.designsystem.components.TiraConexion
import pe.lactocolus.mobile.core.navigation.Destino
import pe.lactocolus.mobile.core.navigation.Navegador
import pe.lactocolus.mobile.core.navigation.barraDeRol
import pe.lactocolus.mobile.core.navigation.inicioDeRol
import pe.lactocolus.mobile.core.navigation.sincronizacionDeRol
import pe.lactocolus.mobile.feature.RootViewModel
import pe.lactocolus.mobile.feature.SesionUi
import pe.lactocolus.mobile.feature.auth.LoginScreen
import pe.lactocolus.mobile.feature.auth.SinAccesoScreen
import pe.lactocolus.mobile.feature.NavHostApp
import org.koin.compose.koinInject

@Composable
fun App() {
    KoinContext {
        val root: RootViewModel = koinViewModel()
        val sesion by root.sesionUi.collectAsStateWithLifecycle()
        val tira by root.tira.collectAsStateWithLifecycle()
        val reloj: Reloj = koinInject()
        var tema by remember { mutableStateOf(TemaApp.SISTEMA) }

        LactoColusTheme(tema) {
            when (val s = sesion) {
                SesionUi.Cargando -> Box(Modifier.fillMaxSize()) { EstadoCargando("Iniciando LactoColus…") }
                SesionUi.SinSesion -> LoginScreen()
                is SesionUi.SinAccesoMovil -> SinAccesoScreen(s.usuario)
                is SesionUi.Autenticado -> {
                    val navegador = remember(s.usuario.rol) { Navegador(inicioDeRol(s.usuario.rol)) }
                    val items = remember(s.usuario.rol) { barraDeRol(s.usuario.rol) }
                    val snackbar = remember { SnackbarHostState() }
                    val scope = rememberCoroutineScope()

                    Scaffold(
                        topBar = {
                            Column {
                                BarraSuperior(
                                    titulo = tituloDe(navegador.actual),
                                    puedeVolver = navegador.puedeVolver,
                                    onVolver = { navegador.atras() },
                                    onNotificaciones = { navegador.navegar(Destino.Notificaciones) },
                                )
                                TiraConexion(
                                    tira,
                                    reloj.ahoraMillis(),
                                    onClick = sincronizacionDeRol(s.usuario.rol)?.let { destino -> { navegador.navegar(destino) } },
                                )
                            }
                        },
                        bottomBar = {
                            BarraInferior(items, navegador.actual.ruta) { item ->
                                navegador.seleccionarPestana(item.destino)
                            }
                        },
                        snackbarHost = { SnackbarHost(snackbar) },
                    ) { padding ->
                        Box(Modifier.fillMaxSize().padding(padding)) {
                            NavHostApp(
                                rol = s.usuario.rol,
                                navegador = navegador,
                                tema = tema,
                                onTema = { tema = it },
                                mostrarMensaje = { msg -> scope.launch { snackbar.showSnackbar(msg) } },
                            )
                        }
                    }
                }
            }
        }
    }
}

private fun tituloDe(destino: Destino): String = when (destino) {
    Destino.Splash -> "LactoColus"
    Destino.Login -> "Iniciar sesión"
    Destino.RecolectorInicio -> "Inicio"
    Destino.RecolectorRutas -> "Mis rutas"
    is Destino.RecolectorRuta -> "Detalle de ruta"
    is Destino.RecolectorRegistro -> "Registrar entrega"
    is Destino.RecolectorConfirmacion -> "Confirmación"
    is Destino.RecolectorResumen -> "Resumen de jornada"
    Destino.RecolectorSync, Destino.CalidadSync -> "Sincronización"
    Destino.RecolectorHistorial -> "Historial de jornadas"
    Destino.CalidadInicio -> "Inicio"
    Destino.CalidadBuscar -> "Buscar productor"
    is Destino.CalidadAnalisis -> "Nuevo análisis"
    is Destino.CalidadResultado -> "Resultado"
    Destino.CalidadHistorial -> "Historial de calidad"
    Destino.ProductorInicio -> "Inicio"
    Destino.ProductorEntregas -> "Mis entregas"
    Destino.ProductorCalidad -> "Mi calidad"
    Destino.ProductorMas -> "Más"
    Destino.ProductorRanking -> "Muro de Honor"
    Destino.ProductorComunicados -> "Comunicados"
    Destino.ProductorTraslado -> "Solicitar traslado"
    Destino.ProductorLiquidaciones -> "Mis liquidaciones"
    Destino.Notificaciones -> "Notificaciones"
    Destino.Perfil -> "Perfil"
}

@Composable
@Preview
fun AppPreview() {
    LactoColusTheme { }
}
