package pe.lactocolus.mobile.core.common

import kotlinx.datetime.Instant
import kotlinx.datetime.TimeZone
import kotlinx.datetime.toLocalDateTime

/** `yyyy-MM-dd` (`fecha_operativa` shape) for the given instant, in the plant's local calendar day. */
fun fechaOperativaIso(millis: Long): String {
    val dt = Instant.fromEpochMilliseconds(millis).toLocalDateTime(TimeZone.of("America/Lima"))
    val mes = dt.monthNumber.toString().padStart(2, '0')
    val dia = dt.dayOfMonth.toString().padStart(2, '0')
    return "${dt.year}-$mes-$dia"
}

/**
 * ISO-8601 with Lima's fixed UTC-05:00 offset (Peru has no DST) — the shape the backend's
 * sync endpoints expect for `recolectada_at`/`muestra_at`. Never use [pe.lactocolus.mobile.core
 * .ui.Formato.fechaHora] (dd/MM/yyyy, display-only) for anything sent over the wire: PHP's date
 * parser reads slash dates as m/d/Y and silently misreads any day <= 12.
 */
fun fechaHoraIso(millis: Long): String {
    val dt = Instant.fromEpochMilliseconds(millis).toLocalDateTime(TimeZone.of("America/Lima"))
    fun dosDigitos(n: Int) = n.toString().padStart(2, '0')
    return "${dt.year}-${dosDigitos(dt.monthNumber)}-${dosDigitos(dt.dayOfMonth)}" +
        "T${dosDigitos(dt.hour)}:${dosDigitos(dt.minute)}:${dosDigitos(dt.second)}-05:00"
}
