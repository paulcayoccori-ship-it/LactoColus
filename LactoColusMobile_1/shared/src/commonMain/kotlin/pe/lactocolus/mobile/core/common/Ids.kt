package pe.lactocolus.mobile.core.common

import kotlin.random.Random

/** RFC-4122 v4 UUID string. Every device-born record gets one as its `id_local`. */
fun randomUuid(): String {
    val bytes = ByteArray(16) { Random.nextInt(0, 256).toByte() }
    bytes[6] = ((bytes[6].toInt() and 0x0F) or 0x40).toByte()
    bytes[8] = ((bytes[8].toInt() and 0x3F) or 0x80).toByte()
    val hex = bytes.joinToString("") { (it.toInt() and 0xFF).toString(16).padStart(2, '0') }
    return "${hex.substring(0, 8)}-${hex.substring(8, 12)}-${hex.substring(12, 16)}-" +
        "${hex.substring(16, 20)}-${hex.substring(20)}"
}

/**
 * Idempotency key for a record: stable for the life of the record so a retry never creates a
 * duplicate on the plant side. We reuse the record's own `id_local` (a v4 UUID) as the key,
 * matching the backend contracts (`uuid_cliente` / `uuid_externo`).
 */
fun claveIdempotencia(idLocal: String): String = idLocal
