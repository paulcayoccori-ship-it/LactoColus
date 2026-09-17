package pe.lactocolus.mobile.di

import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import org.koin.core.module.Module
import org.koin.core.module.dsl.factoryOf
import org.koin.core.module.dsl.singleOf
import org.koin.dsl.bind
import org.koin.dsl.module
import pe.lactocolus.mobile.core.common.DefaultDispatcherProvider
import pe.lactocolus.mobile.core.common.DispatcherProvider
import pe.lactocolus.mobile.core.common.Reloj
import pe.lactocolus.mobile.core.common.RelojSistema
import pe.lactocolus.mobile.data.local.DriverFactory
import pe.lactocolus.mobile.data.local.createDatabase
import pe.lactocolus.mobile.data.mock.SeedRepositoryImpl
import pe.lactocolus.mobile.data.remote.KtorLactoColusApi
import pe.lactocolus.mobile.data.remote.LactoColusApi
import pe.lactocolus.mobile.data.remote.crearHttpClientLactoColus
import pe.lactocolus.mobile.data.repository.AnalisisRepositoryImpl
import pe.lactocolus.mobile.data.repository.AuthRepositoryImpl
import pe.lactocolus.mobile.data.repository.CalidadJornadaRepositoryImpl
import pe.lactocolus.mobile.data.repository.ColaSyncWriter
import pe.lactocolus.mobile.data.repository.ComunicadoRepositoryImpl
import pe.lactocolus.mobile.data.repository.EntregaRepositoryImpl
import pe.lactocolus.mobile.data.repository.JornadaRepositoryImpl
import pe.lactocolus.mobile.data.repository.LiquidacionRepositoryImpl
import pe.lactocolus.mobile.data.repository.RankingRepositoryImpl
import pe.lactocolus.mobile.data.repository.RutaRepositoryImpl
import pe.lactocolus.mobile.data.repository.TrasladoRepositoryImpl
import pe.lactocolus.mobile.data.sync.ConnectivityObserver
import pe.lactocolus.mobile.data.sync.EstadoApp
import pe.lactocolus.mobile.data.sync.SyncEngine
import pe.lactocolus.mobile.domain.repository.AnalisisRepository
import pe.lactocolus.mobile.domain.repository.AuthRepository
import pe.lactocolus.mobile.domain.repository.CalidadJornadaRepository
import pe.lactocolus.mobile.domain.repository.ComunicadoRepository
import pe.lactocolus.mobile.domain.repository.EntregaRepository
import pe.lactocolus.mobile.domain.repository.JornadaRepository
import pe.lactocolus.mobile.domain.repository.LiquidacionRepository
import pe.lactocolus.mobile.domain.repository.RankingRepository
import pe.lactocolus.mobile.domain.repository.RutaRepository
import pe.lactocolus.mobile.domain.repository.SeedRepository
import pe.lactocolus.mobile.domain.repository.SyncRepository
import pe.lactocolus.mobile.domain.repository.TrasladoRepository
import pe.lactocolus.mobile.domain.usecase.AbrirJornada
import pe.lactocolus.mobile.domain.usecase.BorrarDatosLocales
import pe.lactocolus.mobile.domain.usecase.BuscarProductores
import pe.lactocolus.mobile.domain.usecase.CerrarJornada
import pe.lactocolus.mobile.domain.usecase.CerrarSesion
import pe.lactocolus.mobile.domain.usecase.CorregirEntrega
import pe.lactocolus.mobile.domain.usecase.DescargarJornadasCalidad
import pe.lactocolus.mobile.domain.usecase.IniciarJornadaDelDia
import pe.lactocolus.mobile.domain.usecase.IniciarSesion
import pe.lactocolus.mobile.domain.usecase.MarcarComunicadoLeido
import pe.lactocolus.mobile.domain.usecase.ObservarAnalisis
import pe.lactocolus.mobile.domain.usecase.ObservarColaSync
import pe.lactocolus.mobile.domain.usecase.ObservarComunicados
import pe.lactocolus.mobile.domain.usecase.ObservarEntregasDeJornada
import pe.lactocolus.mobile.domain.usecase.ObservarEntregasDeJornadaCalidad
import pe.lactocolus.mobile.domain.usecase.ObservarJornadaActiva
import pe.lactocolus.mobile.domain.usecase.ObservarJornadas
import pe.lactocolus.mobile.domain.usecase.ObservarJornadasCalidad
import pe.lactocolus.mobile.domain.usecase.ObservarLiquidaciones
import pe.lactocolus.mobile.domain.usecase.ObservarNoLeidos
import pe.lactocolus.mobile.domain.usecase.ObservarPendientes
import pe.lactocolus.mobile.domain.usecase.ObservarRanking
import pe.lactocolus.mobile.domain.usecase.ObservarResumenCalidad
import pe.lactocolus.mobile.domain.usecase.ObservarRutas
import pe.lactocolus.mobile.domain.usecase.ObservarRutasRanking
import pe.lactocolus.mobile.domain.usecase.ObservarProductoresDeRuta
import pe.lactocolus.mobile.domain.usecase.ObservarSesion
import pe.lactocolus.mobile.domain.usecase.ObservarSolicitudesTraslado
import pe.lactocolus.mobile.domain.usecase.ObtenerAnalisis
import pe.lactocolus.mobile.domain.usecase.ObtenerLiquidacion
import pe.lactocolus.mobile.domain.usecase.ObtenerProductorPorIdRemoto
import pe.lactocolus.mobile.domain.usecase.DescargarJornadas
import pe.lactocolus.mobile.domain.usecase.DescargarProductores
import pe.lactocolus.mobile.domain.usecase.DescargarRutas
import pe.lactocolus.mobile.domain.usecase.ObtenerProductor
import pe.lactocolus.mobile.domain.usecase.RegistrarAnalisis
import pe.lactocolus.mobile.domain.usecase.RegistrarEntrega
import pe.lactocolus.mobile.domain.usecase.ReintentarSync
import pe.lactocolus.mobile.domain.usecase.SincronizarTodo
import pe.lactocolus.mobile.domain.usecase.SolicitarTraslado
import pe.lactocolus.mobile.domain.usecase.ValidarAnalisis

/** Platform module supplies [DriverFactory] and [ConnectivityObserver] (need a Context on Android). */
expect val platformModule: Module

val coreModule = module {
    single<DispatcherProvider> { DefaultDispatcherProvider() }
    single<Reloj> { RelojSistema() }
    single { EstadoApp() }
    single { createDatabase(get<DriverFactory>()) }
    single { crearHttpClientLactoColus() }
    single<LactoColusApi> { KtorLactoColusApi(get(), get()) }
    single { ColaSyncWriter(get(), get()) }
    // Vive tanto como el proceso: solo se usa para disparar sincronizarTodo() en segundo plano
    // justo después de registrar una entrega (spec: "el recolector no pulsa nada"), sin atarlo
    // al viewModelScope de la pantalla que hizo el registro.
    single { CoroutineScope(SupervisorJob() + Dispatchers.Default) }
}

val dataModule = module {
    single<SeedRepository> { SeedRepositoryImpl(get(), get(), get()) }
    single<AuthRepository> { AuthRepositoryImpl(get(), get(), get(), get(), get()) }
    single<RutaRepository> { RutaRepositoryImpl(get(), get(), get(), get(), get()) }
    single<JornadaRepository> { JornadaRepositoryImpl(get(), get(), get(), get(), get(), get()) }
    single<EntregaRepository> { EntregaRepositoryImpl(get(), get(), get(), get(), get(), get()) }
    single<AnalisisRepository> { AnalisisRepositoryImpl(get(), get(), get(), get(), get(), get()) }
    single<CalidadJornadaRepository> { CalidadJornadaRepositoryImpl(get(), get(), get(), get(), get()) }
    single<ComunicadoRepository> { ComunicadoRepositoryImpl(get(), get(), get()) }
    single<LiquidacionRepository> { LiquidacionRepositoryImpl(get(), get()) }
    single<RankingRepository> { RankingRepositoryImpl(get(), get()) }
    single<TrasladoRepository> { TrasladoRepositoryImpl(get(), get(), get(), get()) }
    single<SyncRepository> { SyncEngine(get(), get(), get(), get(), get()) }
}

val domainModule = module {
    factoryOf(::ObservarSesion); factoryOf(::IniciarSesion); factoryOf(::CerrarSesion); factoryOf(::BorrarDatosLocales)
    factoryOf(::ObservarRutas); factoryOf(::ObservarProductoresDeRuta); factoryOf(::BuscarProductores); factoryOf(::ObtenerProductor); factoryOf(::DescargarProductores); factoryOf(::DescargarRutas)
    factoryOf(::ObservarJornadaActiva); factoryOf(::ObservarJornadas); factoryOf(::DescargarJornadas); factoryOf(::IniciarJornadaDelDia)
    factoryOf(::AbrirJornada); factoryOf(::CerrarJornada)
    factoryOf(::ObservarEntregasDeJornada); factoryOf(::RegistrarEntrega); factoryOf(::CorregirEntrega)
    factoryOf(::ObservarAnalisis); factoryOf(::ObservarResumenCalidad); factoryOf(::ObtenerAnalisis); factoryOf(::ValidarAnalisis); factoryOf(::RegistrarAnalisis)
    factoryOf(::ObservarJornadasCalidad); factoryOf(::ObservarEntregasDeJornadaCalidad); factoryOf(::DescargarJornadasCalidad); factoryOf(::ObtenerProductorPorIdRemoto)
    factoryOf(::ObservarComunicados); factoryOf(::ObservarNoLeidos); factoryOf(::MarcarComunicadoLeido)
    factoryOf(::ObservarLiquidaciones); factoryOf(::ObtenerLiquidacion)
    factoryOf(::ObservarRanking); factoryOf(::ObservarRutasRanking)
    factoryOf(::ObservarSolicitudesTraslado); factoryOf(::SolicitarTraslado)
    factoryOf(::ObservarColaSync); factoryOf(::ObservarPendientes); factoryOf(::SincronizarTodo); factoryOf(::ReintentarSync)
}

fun sharedModules(): List<Module> = listOf(platformModule, coreModule, dataModule, domainModule, featureModule)
