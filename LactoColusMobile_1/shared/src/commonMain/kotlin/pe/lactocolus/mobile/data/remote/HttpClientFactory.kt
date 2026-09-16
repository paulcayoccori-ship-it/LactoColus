package pe.lactocolus.mobile.data.remote

import io.ktor.client.HttpClient
import io.ktor.client.plugins.contentnegotiation.ContentNegotiation
import io.ktor.client.plugins.logging.LogLevel
import io.ktor.client.plugins.logging.Logging
import io.ktor.serialization.kotlinx.json.json
import kotlinx.serialization.json.Json

/**
 * Builds the [HttpClient] shared by every `data/remote` API implementation.
 * The concrete engine (OkHttp on Android) is resolved automatically from
 * whatever engine artifact is on that platform's classpath — this function
 * itself stays platform-agnostic.
 */
fun crearHttpClientLactoColus(): HttpClient = HttpClient {
    install(ContentNegotiation) {
        json(
            Json {
                ignoreUnknownKeys = true
                isLenient = true
                // Optional nullable request fields (e.g. AnalisisSyncDto.densidadCorregida) must be
                // omitted, not sent as an explicit JSON null — some backend validators reject a
                // present-but-null field that isn't declared `nullable`.
                explicitNulls = false
            },
        )
    }
    install(Logging) {
        level = LogLevel.INFO
    }
}
