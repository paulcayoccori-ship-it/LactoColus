package pe.lactocolus.mobile.core.ui

import kotlinx.datetime.Instant
import kotlinx.datetime.TimeZone
import kotlinx.datetime.toLocalDateTime

/** Presentation-only formatters. No rounding of stored values — only display. */
object Formato {

    private val zona = TimeZone.of("America/Lima")

    fun litros(valor: Double?): String =
        if (valor == null) "—" else "${decimal(valor, 1)} L"

    fun litrosCorto(valor: Double?): String =
        if (valor == null) "—" else decimal(valor, 1)

    fun soles(valor: Double): String = "S/ ${decimal(valor, 2)}"

    fun puntuacion(valor: Double): String = decimal(valor, 1)

    fun porcentaje(valor: Double): String = "${decimal(valor, 1)} %"

    fun fechaHora(millis: Long?): String {
        if (millis == null) return "—"
        val dt = Instant.fromEpochMilliseconds(millis).toLocalDateTime(zona)
        return "${dosDigitos(dt.dayOfMonth)}/${dosDigitos(dt.monthNumber)}/${dt.year} " +
            "${dosDigitos(dt.hour)}:${dosDigitos(dt.minute)}"
    }

    fun fecha(millis: Long?): String {
        if (millis == null) return "—"
        val dt = Instant.fromEpochMilliseconds(millis).toLocalDateTime(zona)
        return "${dosDigitos(dt.dayOfMonth)}/${dosDigitos(dt.monthNumber)}/${dt.year}"
    }

    fun hora(millis: Long?): String {
        if (millis == null) return "—"
        val dt = Instant.fromEpochMilliseconds(millis).toLocalDateTime(zona)
        return "${dosDigitos(dt.hour)}:${dosDigitos(dt.minute)}"
    }

    /** "hace 5 min" / "hace 2 h" / date — for last-sync labels. */
    fun relativo(millis: Long?, ahoraMillis: Long): String {
        if (millis == null) return "nunca"
        val delta = ahoraMillis - millis
        return when {
            delta < 60_000 -> "hace instantes"
            delta < 3_600_000 -> "hace ${delta / 60_000} min"
            delta < 86_400_000 -> "hace ${delta / 3_600_000} h"
            else -> fecha(millis)
        }
    }

    private fun dosDigitos(n: Int): String = if (n < 10) "0$n" else "$n"

    /** Fixed-decimal formatting without java.text (KMP-safe). */
    fun decimal(valor: Double, decimales: Int): String {
        val negativo = valor < 0
        var v = kotlin.math.abs(valor)
        var factor = 1.0
        repeat(decimales) { factor *= 10 }
        val redondeado = kotlin.math.round(v * factor) / factor
        val entero = redondeado.toLong()
        val fraccion = ((redondeado - entero) * factor).let { kotlin.math.round(it) }.toLong()
        val fracStr = fraccion.toString().padStart(decimales, '0')
        val cuerpo = if (decimales == 0) "$entero" else "$entero,$fracStr"
        return if (negativo && redondeado != 0.0) "-$cuerpo" else cuerpo
    }
}
