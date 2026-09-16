package pe.lactocolus.mobile.data.repository

import app.cash.sqldelight.coroutines.asFlow
import app.cash.sqldelight.coroutines.mapToList
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.withContext
import kotlinx.datetime.TimeZone
import kotlinx.datetime.atStartOfDayIn
import kotlinx.datetime.toLocalDateTime
import pe.lactocolus.mobile.core.common.DispatcherProvider
import pe.lactocolus.mobile.core.common.Reloj
import pe.lactocolus.mobile.core.common.Result
import pe.lactocolus.mobile.core.common.fechaHoraIso
import pe.lactocolus.mobile.core.common.randomUuid
import pe.lactocolus.mobile.core.ui.Formato
import pe.lactocolus.mobile.data.local.toDomain
import pe.lactocolus.mobile.db.LactoColusDb
import pe.lactocolus.mobile.domain.model.Analisis
import pe.lactocolus.mobile.domain.model.ParametroAnalisis
import pe.lactocolus.mobile.domain.model.ResultadoAnalisis
import pe.lactocolus.mobile.domain.model.TipoEntidadSync
import pe.lactocolus.mobile.domain.repository.AnalisisRepository
import pe.lactocolus.mobile.domain.repository.ResumenCalidad

class AnalisisRepositoryImpl(
    private val db: LactoColusDb,
    private val dispatchers: DispatcherProvider,
    private val reloj: Reloj,
    private val cola: ColaSyncWriter,
) : AnalisisRepository {

    private val q get() = db.calidadQueries

    override fun analisis(): Flow<List<Analisis>> =
        q.analisis().asFlow().mapToList(dispatchers.io).map { list -> list.map { it.conParametros() } }

    override fun analisisDeProductor(productorId: String): Flow<List<Analisis>> =
        q.analisisDeProductor(productorId).asFlow().mapToList(dispatchers.io).map { list -> list.map { it.conParametros() } }

    override suspend fun analisisPorId(idLocal: String): Analisis? = withContext(dispatchers.io) {
        q.analisisPorId(idLocal).executeAsOneOrNull()?.conParametros()
    }

    override fun resumenHoy(): Flow<ResumenCalidad> {
        val (desde, hasta) = rangoHoy()
        return combine(
            q.analisisDeHoy(desde, hasta).asFlow().mapToList(dispatchers.io),
            q.contarObservados().asFlow().mapToList(dispatchers.io),
            q.contarPendientesRevision().asFlow().mapToList(dispatchers.io),
            q.contarAnalisisSinSync().asFlow().mapToList(dispatchers.io),
        ) { hoy, observados, pendientes, sinSync ->
            ResumenCalidad(
                hoy = hoy.size,
                observados = observados.firstOrNull()?.toInt() ?: 0,
                pendientes = pendientes.firstOrNull()?.toInt() ?: 0,
                sinSincronizar = sinSync.firstOrNull()?.toInt() ?: 0,
            )
        }
    }

    override suspend fun registrarAnalisis(
        productorId: String,
        fechaMillis: Long,
        equipo: String?,
        parametros: List<ParametroAnalisis>,
        aguaAnadida: Double,
        observaciones: String?,
    ): Result<Analisis> = withContext(dispatchers.io) {
        val id = randomUuid()
        // Si el equipo reporta agua añadida > 0, la muestra queda observada (spec §5).
        val resultado = if (aguaAnadida > 0.0) ResultadoAnalisis.OBSERVADO else ResultadoAnalisis.PENDIENTE_REVISION
        db.transaction {
            q.insertAnalisis(
                id_local = id,
                id_remoto = null,
                uuid_publico = null,
                productor_id = productorId,
                fecha = fechaMillis,
                equipo = equipo,
                fuente = "manual",
                resultado = resultado.name,
                agua_anadida = aguaAnadida,
                observaciones = observaciones,
                estado_sync = "PENDIENTE",
                sync_mensaje = null,
                sync_intentos = 0L,
                clave_idempotencia = id,
            )
            parametros.forEach { p ->
                q.insertParametro(
                    id_local = randomUuid(),
                    analisis_id = id,
                    clave = p.clave,
                    valor = p.valor,
                    unidad = p.unidad,
                    dentro_de_rango = p.dentroDeRango?.let { if (it) 1L else 0L },
                    limite_min = p.limiteMin,
                    limite_max = p.limiteMax,
                )
            }
        }
        // Named per-field payload (not a joined blob) so SyncEngine can read each backend-required
        // parameter directly and detect a missing one instead of sending it as a fabricated zero.
        val porClave = parametros.associateBy { it.clave }
        fun valorTexto(clave: String): String? = porClave[clave]?.valor?.let { Formato.decimal(it, 4).replace(',', '.') }
        val payload = jsonPayload(
            "uuid_externo" to id,
            "productor_id" to productorId,
            "muestra_at" to fechaHoraIso(fechaMillis),
            "equipo" to equipo,
            "fuente" to "manual",
            "grasa" to valorTexto("grasa"),
            "proteina" to valorTexto("proteina"),
            "lactosa" to valorTexto("lactosa"),
            "densidad_medida" to valorTexto("densidad_medida"),
            "temperatura" to valorTexto("temperatura"),
            "solidos_no_grasos" to valorTexto("solidos_no_grasos"),
            "ph" to valorTexto("ph"),
            "acidez" to valorTexto("acidez"),
            "agua_anadida" to Formato.decimal(aguaAnadida, 4).replace(',', '.'),
            "densidad_corregida" to valorTexto("densidad_corregida"),
            "solidos_totales" to valorTexto("solidos_totales"),
            "observaciones" to observaciones,
        )
        cola.encolar(TipoEntidadSync.ANALISIS, id, id, payload)
        Result.Success(q.analisisPorId(id).executeAsOne().conParametros())
    }

    private fun pe.lactocolus.mobile.db.Analisis.conParametros(): Analisis {
        val params = q.parametrosDeAnalisis(id_local).executeAsList().map { it.toDomain() }
        return toDomain(params)
    }

    private fun rangoHoy(): Pair<Long, Long> {
        val zona = TimeZone.of("America/Lima")
        val hoy = reloj.ahora().toLocalDateTime(zona).date
        val inicio = hoy.atStartOfDayIn(zona).toEpochMilliseconds()
        return inicio to (inicio + 24L * 60 * 60 * 1000)
    }
}
