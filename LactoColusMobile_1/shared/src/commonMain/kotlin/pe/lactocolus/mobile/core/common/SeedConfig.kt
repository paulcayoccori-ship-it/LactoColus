package pe.lactocolus.mobile.core.common

/**
 * Single switch for whether [pe.lactocolus.mobile.data.mock.SeedRepositoryImpl] is allowed to
 * populate the local store on login. Keep it `false` for real usage against the backend — flip
 * it to `true` only for previews or manual development without a backend, where seeing example
 * data is the point.
 */
object SeedConfig {
    const val SEMBRAR_DATOS_DEMO: Boolean = false
}
