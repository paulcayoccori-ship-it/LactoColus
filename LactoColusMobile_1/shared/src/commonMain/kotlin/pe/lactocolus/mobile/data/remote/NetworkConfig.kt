package pe.lactocolus.mobile.data.remote

/**
 * Single place to configure how the app reaches the Laravel backend.
 *
 * [BASE_URL] defaults to the debug value verified against `POST /api/v1/login`
 * (`http://127.0.0.1:8000/api/v1`). That address only resolves from the same
 * host as the Laravel dev server (e.g. `adb reverse tcp:8000 tcp:8000`, or an
 * iOS simulator). From the **Android emulator** the host loopback is reached
 * at `10.0.2.2` instead, and a physical device needs the host's LAN IP —
 * change this constant accordingly for those cases.
 */
object NetworkConfig {
    const val BASE_URL: String = "http://127.0.0.1:8000/api/v1"
}
