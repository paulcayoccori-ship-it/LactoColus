package pe.lactocolus.mobile.data.sync

import kotlinx.coroutines.flow.Flow

/**
 * Emits connectivity changes. The Android actual uses ConnectivityManager; iOS uses
 * NWPathMonitor. When connectivity is regained the [AutoSync] worker fires the queue (spec §3).
 */
expect class ConnectivityObserver {
    fun observar(): Flow<Boolean>
}
