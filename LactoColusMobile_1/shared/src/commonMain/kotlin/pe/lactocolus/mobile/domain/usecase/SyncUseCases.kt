package pe.lactocolus.mobile.domain.usecase

import kotlinx.coroutines.flow.Flow
import pe.lactocolus.mobile.core.common.Result
import pe.lactocolus.mobile.domain.model.ColaSyncItem
import pe.lactocolus.mobile.domain.repository.SyncRepository

class ObservarColaSync(private val repo: SyncRepository) {
    operator fun invoke(): Flow<List<ColaSyncItem>> = repo.cola()
}

class ObservarPendientes(private val repo: SyncRepository) {
    operator fun invoke(): Flow<Int> = repo.pendientes()
}

class SincronizarTodo(private val repo: SyncRepository) {
    suspend operator fun invoke(): Result<Unit> = repo.sincronizarTodo()
}

class ReintentarSync(private val repo: SyncRepository) {
    suspend operator fun invoke(idLocal: String): Result<Unit> = repo.reintentar(idLocal)
}
