package pe.lactocolus.mobile.di

import org.koin.core.module.Module
import org.koin.dsl.module
import pe.lactocolus.mobile.data.local.DriverFactory
import pe.lactocolus.mobile.data.sync.ConnectivityObserver

actual val platformModule: Module = module {
    single { DriverFactory() }
    single { ConnectivityObserver() }
}
