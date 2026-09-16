package pe.lactocolus.mobile.domain.usecase

import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import pe.lactocolus.mobile.core.common.AppError
import pe.lactocolus.mobile.core.common.Reloj
import pe.lactocolus.mobile.core.common.Result
import pe.lactocolus.mobile.core.common.fechaOperativaIso
import pe.lactocolus.mobile.domain.model.Entrega
import pe.lactocolus.mobile.domain.model.EstadoJornada
import pe.lactocolus.mobile.domain.model.Jornada
import pe.lactocolus.mobile.domain.model.Productor
import pe.lactocolus.mobile.domain.model.Ruta
import pe.lactocolus.mobile.domain.repository.EntregaRepository
import pe.lactocolus.mobile.domain.repository.JornadaRepository
import pe.lactocolus.mobile.domain.repository.RutaRepository

class ObservarRutas(private val repo: RutaRepository) {
    operator fun invoke(): Flow<List<Ruta>> = repo.rutas()
}

class ObservarProductoresDeRuta(private val repo: RutaRepository) {
    operator fun invoke(rutaId: String): Flow<List<Productor>> = repo.productoresDeRuta(rutaId)
}

class BuscarProductores(private val repo: RutaRepository) {
    operator fun invoke(query: String): Flow<List<Productor>> = repo.buscarProductores(query)
}

class ObtenerProductor(private val repo: RutaRepository) {
    suspend operator fun invoke(idLocal: String): Productor? = repo.productor(idLocal)
}

/** Background catalog refresh — see [RutaRepository.descargarProductores]. */
class DescargarProductores(private val repo: RutaRepository) {
    suspend operator fun invoke(): Result<Unit> = repo.descargarProductores()
}

/** Background catalog refresh — see [RutaRepository.descargarRutas]. */
class DescargarRutas(private val repo: RutaRepository) {
    suspend operator fun invoke(): Result<Unit> = repo.descargarRutas()
}

/** Background catalog refresh — see [JornadaRepository.descargarJornadas]. */
class DescargarJornadas(private val repo: JornadaRepository) {
    suspend operator fun invoke(): Result<Unit> = repo.descargarJornadas()
}

class ObservarJornadaActiva(private val repo: JornadaRepository) {
    operator fun invoke(): Flow<Jornada?> = repo.jornadaAbierta()
}

class ObservarJornadas(private val repo: JornadaRepository) {
    operator fun invoke(): Flow<List<Jornada>> = repo.jornadas()
}

/**
 * Arranque de jornada sin formulario: un botón. Decide si ya hay una jornada abierta hoy antes
 * de crear una nueva — esta regla vive aquí y no en el ViewModel ni en el composable. Si el
 * recolector no tiene ruta asignada, falla con un mensaje claro en vez de intentar crear nada.
 */
class IniciarJornadaDelDia(
    private val rutas: RutaRepository,
    private val jornadas: JornadaRepository,
    private val reloj: Reloj,
) {
    suspend operator fun invoke(): Result<Jornada> {
        val hoy = fechaOperativaIso(reloj.ahoraMillis())
        val abierta = jornadas.jornadaAbiertaAhora()
        if (abierta != null && abierta.fechaOperativa == hoy) return Result.Success(abierta)

        val ruta = rutas.rutas().first().firstOrNull()
            ?: return Result.Failure(AppError.Validacion("No tienes una ruta asignada. Contacta al administrador.", "ruta"))

        return jornadas.iniciarJornadaHoy(ruta.idLocal, hoy)
    }
}

class AbrirJornada(private val repo: JornadaRepository) {
    suspend operator fun invoke(rutaId: String, turno: String, fechaOperativa: String, observaciones: String?): Result<Jornada> {
        if (rutaId.isBlank()) return Result.Failure(AppError.Validacion("Elige una ruta", "ruta"))
        return repo.abrirJornada(rutaId, turno, fechaOperativa, observaciones?.trim().takeUnless { it.isNullOrEmpty() })
    }
}

class CerrarJornada(private val repo: JornadaRepository) {
    suspend operator fun invoke(idLocal: String): Result<Unit> {
        val j = repo.jornada(idLocal) ?: return Result.Failure(AppError.NoEncontrado)
        if (j.estado != EstadoJornada.ABIERTA) {
            return Result.Failure(AppError.Conflicto("La jornada ya no está abierta"))
        }
        return repo.cerrarJornada(idLocal)
    }
}

class ObservarEntregasDeJornada(private val repo: EntregaRepository) {
    operator fun invoke(jornadaId: String): Flow<List<Entrega>> = repo.entregasDeJornada(jornadaId)
}

/**
 * Registrar una entrega (spec §5). Reglas:
 *  - "No entregó" es una alternativa explícita, no litros = 0.
 *  - Litros positivos, hasta 3 decimales, con incrementos de 0,5 en la UI.
 *  - Una desviación grande frente al promedio del productor exige observación.
 *  - Solo se registra en una jornada abierta; una entrega por productor y jornada.
 */
class RegistrarEntrega(
    private val entregas: EntregaRepository,
    private val jornadas: JornadaRepository,
    private val rutas: RutaRepository,
) {
    companion object {
        /** Umbral de desviación relativa frente al promedio que obliga a observación. */
        const val UMBRAL_DESVIACION = 0.4
    }

    suspend operator fun invoke(
        jornadaId: String,
        productorId: String,
        litros: Double?,
        noEntrego: Boolean,
        observacion: String?,
    ): Result<Entrega> {
        val jornada = jornadas.jornada(jornadaId) ?: return Result.Failure(AppError.NoEncontrado)
        if (jornada.estado != EstadoJornada.ABIERTA) {
            return Result.Failure(AppError.Conflicto("La jornada está cerrada; no se pueden registrar entregas"))
        }
        if (entregas.entregaDeProductor(jornadaId, productorId) != null) {
            return Result.Failure(AppError.Conflicto("Este productor ya tiene una entrega en la jornada"))
        }

        val obs = observacion?.trim().takeUnless { it.isNullOrEmpty() }

        if (noEntrego) {
            return entregas.registrarEntrega(jornadaId, productorId, null, true, obs ?: "No entregó")
        }

        if (litros == null || litros <= 0.0) {
            return Result.Failure(AppError.Validacion("Ingresa los litros o marca «No entregó»", "litros"))
        }
        if (litros > 9_999_999.999) {
            return Result.Failure(AppError.Validacion("El valor de litros no es válido", "litros"))
        }

        val promedio = rutas.productor(productorId)?.promedioLitros ?: 0.0
        if (promedio > 0.0) {
            val desviacion = kotlin.math.abs(litros - promedio) / promedio
            if (desviacion >= UMBRAL_DESVIACION && obs == null) {
                return Result.Failure(
                    AppError.Validacion(
                        "El valor difiere mucho del promedio (${promedio}). Agrega una observación.",
                        "observacion",
                    ),
                )
            }
        }

        return entregas.registrarEntrega(jornadaId, productorId, litros, false, obs)
    }
}

class CorregirEntrega(
    private val entregas: EntregaRepository,
    private val jornadas: JornadaRepository,
) {
    suspend operator fun invoke(entregaId: String, jornadaId: String, litros: Double, observacion: String?, motivo: String): Result<Unit> {
        val jornada = jornadas.jornada(jornadaId) ?: return Result.Failure(AppError.NoEncontrado)
        if (jornada.estado != EstadoJornada.ABIERTA) {
            return Result.Failure(AppError.Conflicto("Solo se corrige mientras la jornada sigue abierta"))
        }
        if (motivo.trim().isEmpty()) {
            return Result.Failure(AppError.Validacion("El motivo de la corrección es obligatorio", "motivo"))
        }
        if (litros <= 0.0) {
            return Result.Failure(AppError.Validacion("Los litros deben ser mayores que cero", "litros"))
        }
        return entregas.corregirEntrega(entregaId, litros, observacion?.trim().takeUnless { it.isNullOrEmpty() }, motivo.trim())
    }
}
