package pe.lactocolus.mobile.data.repository

import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonPrimitive

/** Builds a compact JSON object string for a sync payload without pulling a serializer per call. */
internal fun jsonPayload(vararg campos: Pair<String, String?>): String {
    val obj = JsonObject(
        campos.associate { (k, v) -> k to (v?.let { JsonPrimitive(it) } ?: JsonNull) },
    )
    return obj.toString()
}
