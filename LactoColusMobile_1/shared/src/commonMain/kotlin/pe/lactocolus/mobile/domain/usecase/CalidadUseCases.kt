package pe.lactocolus.mobile.domain.usecase

import kotlinx.coroutines.flow.Flow
import pe.lactocolus.mobile.core.common.AppError
import pe.lactocolus.mobile.core.common.Result
import pe.lactocolus.mobile.domain.model.Analisis
import pe.lactocolus.mobile.domain.model.ParametroAnalisis
import pe.lactocolus.mobile.domain.repository.AnalisisRepository
import pe.lactocolus.mobile.domain.repository.ResumenCalidad

/**
 * Rangos de captura del formulario Lactoscan (backend/docs/control-calidad.md). Son controles
 * amplios de captura para detectar negativos y errores de unidad, NO rangos de conformidad.
 */
enum class ParametroCalidad(
    val clave: String,
    val etiqueta: String,
    val unidad: String,
    val min: Double,
    val max: Double,
    val seccion: SeccionAnalisis,
    /** Uno de los nueve parámetros que `POST /api/v1/calidad/sincronizar` exige siempre. */
    val requerido: Boolean = true,
) {
    GRASA("grasa", "Grasa", "% m/m", 0.0, 100.0, SeccionAnalisis.COMPOSICION),
    PROTEINA("proteina", "Proteína", "% m/m", 0.0, 100.0, SeccionAnalisis.COMPOSICION),
    LACTOSA("lactosa", "Lactosa", "% m/m", 0.0, 100.0, SeccionAnalisis.COMPOSICION),
    SOLIDOS_NO_GRASOS("solidos_no_grasos", "Sólidos no grasos", "% m/m", 0.0, 100.0, SeccionAnalisis.COMPOSICION),
    SOLIDOS_TOTALES("solidos_totales", "Sólidos totales", "% m/m", 0.0, 100.0, SeccionAnalisis.COMPOSICION, requerido = false),
    DENSIDAD_MEDIDA("densidad_medida", "Densidad medida", "g/mL", 0.0001, 2.0, SeccionAnalisis.FISICAS),
    TEMPERATURA("temperatura", "Temperatura", "°C", 0.0, 100.0, SeccionAnalisis.FISICAS),
    DENSIDAD_CORREGIDA("densidad_corregida", "Densidad corregida", "g/mL", 0.0001, 2.0, SeccionAnalisis.FISICAS, requerido = false),
    PH("ph", "pH", "pH", 0.0, 14.0, SeccionAnalisis.ACIDEZ),
    ACIDEZ("acidez", "Acidez", "% ác. láctico", 0.0, 100.0, SeccionAnalisis.ACIDEZ),
    AGUA_ANADIDA("agua_anadida", "Agua añadida", "%", 0.0, 100.0, SeccionAnalisis.ACIDEZ);

    fun enRango(valor: Double): Boolean = valor in min..max
}

enum class SeccionAnalisis(val titulo: String) {
    COMPOSICION("Composición"),
    FISICAS("Propiedades físicas"),
    ACIDEZ("Acidez y adulteración"),
    MUESTRA("Datos de la muestra"),
}

class ObservarAnalisis(private val repo: AnalisisRepository) {
    operator fun invoke(): Flow<List<Analisis>> = repo.analisis()
}

class ObservarResumenCalidad(private val repo: AnalisisRepository) {
    operator fun invoke(): Flow<ResumenCalidad> = repo.resumenHoy()
}

class ObtenerAnalisis(private val repo: AnalisisRepository) {
    suspend operator fun invoke(idLocal: String): Analisis? = repo.analisisPorId(idLocal)
}

/** Resultado de validar el borrador del formulario de análisis, un mensaje por campo inválido. */
data class ResultadoValidacionAnalisis(val errores: Map<ParametroCalidad, String>) {
    val esValido: Boolean get() = errores.isEmpty()
}

/**
 * Valida el borrador del formulario de análisis: los nueve parámetros obligatorios del backend
 * deben estar presentes, ser numéricos, caer dentro del rango de captura y tener máximo 4
 * decimales; `densidad_corregida`/`solidos_totales` siguen opcionales pero se validan igual si
 * se llenan. Pura y sin efectos — [pe.lactocolus.mobile.feature.calidad.CalidadViewModel] la
 * reusa en cada tecleo para habilitar "Guardar" y marcar campos en rojo, y [RegistrarAnalisis]
 * la reusa antes de persistir: una sola fuente para la regla de negocio, el formulario solo
 * refleja el resultado.
 */
class ValidarAnalisis {
    operator fun invoke(valores: Map<ParametroCalidad, String>): ResultadoValidacionAnalisis {
        val errores = mutableMapOf<ParametroCalidad, String>()
        for (param in ParametroCalidad.entries) {
            val texto = valores[param]?.trim().orEmpty()
            if (texto.isEmpty()) {
                if (param.requerido) errores[param] = "Obligatorio"
                continue
            }
            val valor = texto.toDoubleOrNull()
            when {
                valor == null -> errores[param] = "Ingresa un número válido"
                !param.enRango(valor) -> errores[param] = "Debe estar entre ${param.min} y ${param.max} ${param.unidad}"
                decimales(texto) > 4 -> errores[param] = "Máximo 4 decimales"
            }
        }
        return ResultadoValidacionAnalisis(errores)
    }

    private fun decimales(texto: String): Int {
        val punto = texto.indexOf('.')
        return if (punto == -1) 0 else texto.length - punto - 1
    }
}

/**
 * Registrar un análisis. [ValidarAnalisis] exige los nueve parámetros obligatorios del backend,
 * numéricos, en rango y con máximo 4 decimales antes de persistir — el formulario ya bloquea
 * "Guardar" con la misma regla; esto es la última línea de defensa, no una segunda fuente de
 * verdad. Nunca se completa un parámetro faltante con `0.0`: un cero fabricado en, por ejemplo,
 * `agua_anadida` o `ph` sería una afirmación sobre la leche que nadie midió. Si el equipo reporta
 * agua añadida > 0 la muestra queda observada y se avisa de forma destacada. No se declara
 * "conforme" sin un perfil completo (fuera de alcance) — el resultado por defecto es pendiente
 * de revisión.
 */
class RegistrarAnalisis(private val repo: AnalisisRepository, private val validar: ValidarAnalisis) {
    suspend operator fun invoke(
        productorId: String,
        fechaMillis: Long,
        equipo: String?,
        textos: Map<ParametroCalidad, String>,
        observaciones: String?,
    ): Result<Analisis> {
        if (productorId.isBlank()) return Result.Failure(AppError.Validacion("Elige un productor", "productor"))

        val validacion = validar(textos)
        if (!validacion.esValido) {
            val (param, mensaje) = validacion.errores.entries.first()
            return Result.Failure(AppError.Validacion("${param.etiqueta}: $mensaje", param.clave))
        }

        val valores = textos.mapNotNull { (param, texto) -> texto.trim().toDoubleOrNull()?.let { param to it } }.toMap()
        val agua = valores.getValue(ParametroCalidad.AGUA_ANADIDA)
        val parametros = valores.map { (param, valor) ->
            ParametroAnalisis(
                clave = param.clave,
                valor = valor,
                unidad = param.unidad,
                dentroDeRango = null, // sin perfil de conformidad en esta entrega
                limiteMin = null,
                limiteMax = null,
            )
        }
        return repo.registrarAnalisis(productorId, fechaMillis, equipo?.trim().takeUnless { it.isNullOrEmpty() }, parametros, agua, observaciones?.trim().takeUnless { it.isNullOrEmpty() })
    }
}
