package pe.lactocolus.mobile.data.mock

import kotlinx.coroutines.withContext
import pe.lactocolus.mobile.core.common.DispatcherProvider
import pe.lactocolus.mobile.core.common.Reloj
import pe.lactocolus.mobile.core.common.randomUuid
import pe.lactocolus.mobile.db.LactoColusDb
import pe.lactocolus.mobile.domain.model.Rol
import pe.lactocolus.mobile.domain.repository.SeedRepository

/**
 * Debug/preview bootstrap of the local store (spec §9). Idempotent: seeds only when the
 * relevant tables are empty. Covers a ruta activa + productores, a jornada abierta with
 * pending and synced entregas, one análisis conforme and one observado con agua añadida,
 * ranking, comunicados (leído/no leído), a solicitud de traslado and a liquidación semanal.
 */
class SeedRepositoryImpl(
    private val db: LactoColusDb,
    private val dispatchers: DispatcherProvider,
    private val reloj: Reloj,
) : SeedRepository {

    override suspend fun sembrarSiVacio(rol: Rol) = withContext(dispatchers.io) {
        val ahora = reloj.ahoraMillis()
        val dia = 24L * 60 * 60 * 1000

        db.transaction {
            if (db.acopioQueries.rutas().executeAsList().isEmpty()) {
                sembrarAcopio(ahora, dia)
            }
            if (db.calidadQueries.analisis().executeAsList().isEmpty()) {
                sembrarCalidad(ahora, dia)
            }
            if (db.productorQueries.comunicados().executeAsList().isEmpty()) {
                sembrarProductor(ahora, dia)
            }
        }
    }

    private fun sembrarAcopio(ahora: Long, dia: Long) {
        val a = db.acopioQueries
        val rutaNorte = "ruta-norte"
        val rutaSur = "ruta-sur"
        a.insertRuta(rutaNorte, 1, "R-001", "Ruta Norte - Cabana", "primera_vuelta", 1, "activa", 6)
        a.insertRuta(rutaSur, 2, "R-002", "Ruta Sur - Mañazo", "primera_vuelta", 1, "activa", 4)

        val nombres = listOf(
            Triple("Julia", "Condori", 12.5), Triple("Marco", "Huanca", 9.0), Triple("Elena", "Apaza", 15.5),
            Triple("Teófilo", "Mamani", 7.5), Triple("Rosa", "Ccama", 11.0), Triple("Nicolás", "Yana", 18.0),
        )
        val productores = nombres.mapIndexed { i, (nom, ape, prom) ->
            val id = if (i == 0) "prod-julia" else randomUuid()
            a.insertProductor(id, (100 + i).toLong(), "P-${(i + 1).toString().padStart(3, '0')}", nom, ape, rutaNorte, (i + 1).toLong(), 1, prom)
            id
        }
        listOf(Triple("Aurelio", "Ticona", 8.0), Triple("Benita", "Coila", 10.5), Triple("César", "Quenta", 13.0), Triple("Delia", "Larico", 6.5))
            .forEachIndexed { i, (nom, ape, prom) ->
                a.insertProductor(randomUuid(), (200 + i).toLong(), "P-${(i + 7).toString().padStart(3, '0')}", nom, ape, rutaSur, (i + 1).toLong(), 1, prom)
            }

        // Jornada abierta de hoy.
        val jornadaId = "jornada-hoy"
        a.insertJornada(
            id_local = jornadaId, id_remoto = null, uuid_publico = null, ruta_id = rutaNorte,
            turno = "primera_vuelta", vuelta = 1, fecha_operativa = "2026-09-10", estado = "abierta",
            observaciones = "Salida 06:15", total_litros = 0.0, total_entregas = 0,
            abierta_en = ahora - 2 * 60 * 60 * 1000, cerrada_en = null,
            estado_sync = "PENDIENTE", clave_idempotencia = jornadaId,
        )

        // Dos entregas: una sincronizada (CREADO) y una pendiente.
        val e1 = randomUuid()
        a.insertEntrega(e1, 501, jornadaId, productores[0], 12.5, 0, ahora - 90 * 60 * 1000, null, null, null, null, "CREADO", null, 1, e1)
        val e2 = randomUuid()
        a.insertEntrega(e2, null, jornadaId, productores[1], 9.0, 0, ahora - 60 * 60 * 1000, "Bidón extra", null, null, null, "PENDIENTE", null, 0, e2)
        val e3 = randomUuid()
        a.insertEntrega(e3, null, jornadaId, productores[3], null, 1, ahora - 30 * 60 * 1000, "No entregó hoy", null, null, null, "PENDIENTE", null, 0, e3)
        a.actualizarTotalesJornada(jornadaId)

        // Jornada cerrada de ayer para el historial.
        val ayer = "jornada-ayer"
        a.insertJornada(
            id_local = ayer, id_remoto = 400, uuid_publico = randomUuid(), ruta_id = rutaNorte,
            turno = "primera_vuelta", vuelta = 1, fecha_operativa = "2026-09-09", estado = "cerrada",
            observaciones = null, total_litros = 73.5, total_entregas = 6,
            abierta_en = ahora - dia - 3 * 60 * 60 * 1000, cerrada_en = ahora - dia,
            estado_sync = "CREADO", clave_idempotencia = ayer,
        )

        // Cola de sync coherente con las entregas pendientes.
        db.syncQueries.encolar(randomUuid(), "ENTREGA", e2, e2, "{\"uuid_cliente\":\"$e2\",\"jornada_id\":\"$jornadaId\",\"productor_id\":\"${productores[1]}\",\"litros\":\"9.000\",\"recolectada_at\":\"09/09/2026 07:00\",\"observacion\":\"Bidón extra\"}", ahora - 60 * 60 * 1000, ahora - 60 * 60 * 1000)
        db.syncQueries.encolar(randomUuid(), "ENTREGA", e3, e3, "{\"uuid_cliente\":\"$e3\",\"jornada_id\":\"$jornadaId\",\"productor_id\":\"${productores[3]}\",\"litros\":\"0.000\",\"recolectada_at\":\"09/09/2026 07:30\",\"observacion\":\"No entregó hoy\"}", ahora - 30 * 60 * 1000, ahora - 30 * 60 * 1000)
    }

    private fun sembrarCalidad(ahora: Long, dia: Long) {
        val c = db.calidadQueries
        // Asegura padrón para calidad si el rol calidad entró primero.
        if (db.acopioQueries.rutas().executeAsList().isEmpty()) sembrarAcopio(ahora, dia)
        val productorConforme = db.acopioQueries.productorPorId("prod-julia").executeAsOneOrNull()?.id_local
            ?: db.acopioQueries.rutas().executeAsList().let { "prod-julia" }

        val conforme = "analisis-conforme"
        c.insertAnalisis(conforme, 900, randomUuid(), productorConforme, ahora - 3 * 60 * 60 * 1000, "Lactoscan-01", "manual", "conforme", 0.0, "Muestra tomada en finca", "CREADO", null, 1, conforme)
        listOf(
            Triple("grasa", 3.6, "% m/m"), Triple("proteina", 3.2, "% m/m"), Triple("lactosa", 4.7, "% m/m"),
            Triple("densidad_corregida", 1.030, "g/mL"), Triple("acidez", 0.15, "% ác. láctico"),
            Triple("solidos_totales", 12.3, "% m/m"), Triple("temperatura", 8.0, "°C"), Triple("agua_anadida", 0.0, "%"),
        ).forEach { (clave, valor, unidad) ->
            c.insertParametro(randomUuid(), conforme, clave, valor, unidad, 1, null, null)
        }

        val observado = "analisis-observado"
        c.insertAnalisis(observado, null, null, productorConforme, ahora - 40 * 60 * 1000, "Lactoscan-01", "manual", "observado", 3.4, "El equipo reporta agua añadida", "PENDIENTE", null, 0, observado)
        listOf(
            Triple("grasa", 2.1, "% m/m"), Triple("proteina", 2.6, "% m/m"), Triple("densidad_medida", 1.026, "g/mL"),
            Triple("acidez", 0.11, "% ác. láctico"), Triple("agua_anadida", 3.4, "%"), Triple("temperatura", 12.0, "°C"),
        ).forEach { (clave, valor, unidad) ->
            val enRango = clave != "agua_anadida"
            c.insertParametro(randomUuid(), observado, clave, valor, unidad, if (enRango) 1 else 0, null, null)
        }
        db.syncQueries.encolar(randomUuid(), "ANALISIS", observado, observado, "{\"uuid_externo\":\"$observado\",\"productor_id\":\"$productorConforme\",\"muestra_at\":\"10/09/2026 06:20\",\"parametros\":\"grasa=2.1000;agua_anadida=3.4000\"}", ahora - 40 * 60 * 1000, ahora - 40 * 60 * 1000)
    }

    private fun sembrarProductor(ahora: Long, dia: Long) {
        val p = db.productorQueries
        p.insertComunicado(randomUuid(), 1, randomUuid(), "urgente", "Cambio de horario de acopio", "Desde el lunes la recolección de la Ruta Norte empieza a las 05:45.", ahora - 6 * 60 * 60 * 1000, 0, null, "CREADO")
        p.insertComunicado(randomUuid(), 2, randomUuid(), "capacitacion", "Taller de calidad de leche", "Reunión en planta el sábado 13 para revisar buenas prácticas de ordeño.", ahora - 2 * dia, 1, ahora - dia, "CREADO")
        p.insertComunicado(randomUuid(), 3, randomUuid(), "general", "Pago de liquidaciones", "Las liquidaciones de la semana 36 ya están disponibles en la app.", ahora - 3 * dia, 0, null, "CREADO")

        p.insertLiquidacion("liq-36", 3601, "2026-W36", "2026-08-31", "2026-09-06", 78.5, 1.80, 6.0, 0.0, 2.5, 144.8, "pagada")
        p.insertLiquidacion("liq-37", null, "2026-W37", "2026-09-07", "2026-09-13", 71.0, 1.80, 4.0, 3.5, 1.0, 127.3, "pendiente")

        val ranking = listOf(
            listOf("Elena Apaza" to 92.4, "Julia Condori" to 88.1, "Rosa Ccama" to 84.7, "Marco Huanca" to 79.3, "Nicolás Yana" to 71.0),
        ).first()
        listOf("diario" to "2026-09-10", "semanal" to "2026-09-07", "mensual" to "2026-09-01").forEach { (periodo, fecha) ->
            ranking.forEachIndexed { i, (nombre, punt) ->
                p.insertRanking(randomUuid(), periodo, fecha, (i + 1).toLong(), nombre, if (i % 2 == 0) "Ruta Norte" else "Ruta Sur", punt)
            }
        }

        val traslado = "traslado-1"
        p.insertSolicitudTraslado(traslado, null, traslado, "Ruta Norte", "Ruta Sur", "2026-09-25", "Cambio de domicilio a Mañazo", "pendiente", 10, ahora - dia, "PENDIENTE", null, traslado)
        db.syncQueries.encolar(randomUuid(), "TRASLADO", traslado, traslado, "{\"uuid\":\"$traslado\",\"ruta_solicitada_id\":\"Ruta Sur\",\"fecha_efectiva\":\"2026-09-25\",\"motivo\":\"Cambio de domicilio a Mañazo\"}", ahora - dia, ahora - dia)
    }
}
