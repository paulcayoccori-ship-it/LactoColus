package pe.lactocolus.mobile.core.common

import kotlinx.datetime.Instant

/** Injectable clock so time-dependent rules (traslado anticipation, jornada timestamps)
 *  are deterministic in tests. */
interface Reloj {
    fun ahora(): Instant
    fun ahoraMillis(): Long = ahora().toEpochMilliseconds()
}

class RelojSistema : Reloj {
    // Uses the Kotlin 2.4 stdlib clock; kotlinx-datetime 0.6.x's own Clock is not
    // consistently resolvable across targets on this toolchain.
    @OptIn(kotlin.time.ExperimentalTime::class)
    override fun ahora(): Instant =
        Instant.fromEpochMilliseconds(kotlin.time.Clock.System.now().toEpochMilliseconds())
}

class RelojFijo(private var instante: Instant) : Reloj {
    override fun ahora(): Instant = instante
    fun avanzar(nuevo: Instant) { instante = nuevo }
}
