package pe.lactocolus.mobile.di

import org.koin.core.module.dsl.viewModel
import org.koin.core.module.dsl.viewModelOf
import org.koin.dsl.module
import pe.lactocolus.mobile.domain.repository.TrasladoRepository
import pe.lactocolus.mobile.feature.RootViewModel
import pe.lactocolus.mobile.feature.auth.AuthViewModel
import pe.lactocolus.mobile.feature.auth.SinAccesoViewModel
import pe.lactocolus.mobile.feature.calidad.CalidadViewModel
import pe.lactocolus.mobile.feature.perfil.PerfilViewModel
import pe.lactocolus.mobile.feature.productor.ProductorViewModel
import pe.lactocolus.mobile.feature.recolector.RecolectorViewModel
import pe.lactocolus.mobile.feature.sync.SyncViewModel

val featureModule = module {
    viewModelOf(::RootViewModel)
    viewModelOf(::AuthViewModel)
    viewModelOf(::SinAccesoViewModel)
    viewModelOf(::RecolectorViewModel)
    viewModelOf(::CalidadViewModel)
    viewModelOf(::SyncViewModel)
    viewModelOf(::PerfilViewModel)
    viewModel {
        ProductorViewModel(
            get(), get(), get(), get(), get(), get(), get(), get(), get(), get(),
            anticipacionDias = get<TrasladoRepository>().anticipacionDias,
        )
    }
}
