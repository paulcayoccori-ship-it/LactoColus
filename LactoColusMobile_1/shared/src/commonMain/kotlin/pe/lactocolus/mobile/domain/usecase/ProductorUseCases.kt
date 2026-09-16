package pe.lactocolus.mobile.domain.usecase

import kotlinx.coroutines.flow.Flow
import kotlinx.datetime.DateTimeUnit
import kotlinx.datetime.TimeZone
import kotlinx.datetime.plus
import kotlinx.datetime.toLocalDateTime
import pe.lactocolus.mobile.core.common.AppError
import pe.lactocolus.mobile.core.common.Reloj
import pe.lactocolus.mobile.core.common.Result
import pe.lactocolus.mobile.domain.model.Comunicado
import pe.lactocolus.mobile.domain.model.Liquidacion
import pe.lactocolus.mobile.domain.model.RankingEntry
import pe.lactocolus.mobile.domain.model.SolicitudTraslado
import pe.lactocolus.mobile.domain.repository.ComunicadoRepository
import pe.lactocolus.mobile.domain.repository.LiquidacionRepository
import pe.lactocolus.mobile.domain.repository.RankingRepository
import pe.lactocolus.mobile.domain.repository.TrasladoRepository

class ObservarComunicados(private val repo: ComunicadoRepository) {
    operator fun invoke(): Flow<List<Comunicado>> = repo.comunicados()
}

class ObservarNoLeidos(private val repo: ComunicadoRepository) {
    operator fun invoke(): Flow<Int> = repo.noLeidos()
}

class MarcarComunicadoLeido(private val repo: ComunicadoRepository) {
    suspend operator fun invoke(idLocal: String) = repo.marcarLeido(idLocal)
}

class ObservarLiquidaciones(private val repo: LiquidacionRepository) {
    operator fun invoke(): Flow<List<Liquidacion>> = repo.liquidaciones()
}

class ObtenerLiquidacion(private val repo: LiquidacionRepository) {
    suspend operator fun invoke(idLocal: String): Liquidacion? = repo.liquidacion(idLocal)
}

/** Muro de Honor. Solo se exponen nombre/ruta/puntuación (privacidad, spec §5). */
class ObservarRanking(private val repo: RankingRepository) {
    operator fun invoke(periodo: String, fecha: String, ruta: String?): Flow<List<RankingEntry>> =
        repo.ranking(periodo, fecha, ruta)
}

class ObservarRutasRanking(private val repo: RankingRepository) {
    operator fun invoke(): Flow<List<String>> = repo.rutasDisponibles()
}

class ObservarSolicitudesTraslado(private val repo: TrasladoRepository) {
    operator fun invoke(): Flow<List<SolicitudTraslado>> = repo.solicitudes()
}

/**
 * Solicitar traslado. La fecha efectiva debe estar al menos hoy + plazo de anticipación
 * (configurable 1–365; backend/docs/solicitudes-traslado.md). El plazo real llega del
 * repositorio; la UI muestra un resumen antes de enviar.
 */
class SolicitarTraslado(
    private val repo: TrasladoRepository,
    private val reloj: Reloj,
) {
    suspend operator fun invoke(rutaOrigen: String, rutaDestino: String, fechaDeseadaIso: String, motivo: String): Result<SolicitudTraslado> {
        if (rutaDestino.isBlank() || rutaDestino == rutaOrigen) {
            return Result.Failure(AppError.Validacion("Elige una ruta destino distinta", "destino"))
        }
        if (motivo.trim().length < 5) {
            return Result.Failure(AppError.Validacion("Explica el motivo del traslado", "motivo"))
        }
        val hoy = reloj.ahora().toLocalDateTime(TimeZone.of("America/Lima")).date
        val minima = hoy.plus(repo.anticipacionDias, DateTimeUnit.DAY)
        val deseada = runCatching { kotlinx.datetime.LocalDate.parse(fechaDeseadaIso) }.getOrNull()
            ?: return Result.Failure(AppError.Validacion("Fecha inválida", "fecha"))
        if (deseada < minima) {
            return Result.Failure(
                AppError.Validacion("La fecha debe ser desde $minima (${repo.anticipacionDias} días de anticipación)", "fecha"),
            )
        }
        return repo.solicitar(rutaOrigen, rutaDestino, fechaDeseadaIso, motivo.trim())
    }
}
