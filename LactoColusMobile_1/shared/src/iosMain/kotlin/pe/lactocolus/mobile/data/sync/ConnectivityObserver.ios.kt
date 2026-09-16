package pe.lactocolus.mobile.data.sync

import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.flowOf

/**
 * iOS placeholder: assumes connectivity. A real implementation wraps `NWPathMonitor`
 * (Network.framework). Kept minimal so the framework link is not blocked by this feature.
 */
actual class ConnectivityObserver {
    actual fun observar(): Flow<Boolean> = flowOf(true)
}
