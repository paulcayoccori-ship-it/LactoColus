package pe.lactocolus.mobile.data.sync

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import kotlinx.coroutines.channels.awaitClose
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.callbackFlow
import kotlinx.coroutines.flow.distinctUntilChanged

actual class ConnectivityObserver(private val context: Context) {

    actual fun observar(): Flow<Boolean> = callbackFlow {
        val manager = context.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager

        fun conectado(): Boolean {
            val red = manager.activeNetwork ?: return false
            val caps = manager.getNetworkCapabilities(red) ?: return false
            return caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
        }

        val callback = object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) { trySend(true) }
            override fun onLost(network: Network) { trySend(conectado()) }
        }

        trySend(conectado())
        manager.registerDefaultNetworkCallback(callback)
        awaitClose { manager.unregisterNetworkCallback(callback) }
    }.distinctUntilChanged()
}
