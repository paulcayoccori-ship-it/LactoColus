package pe.lactocolus.mobile.data.repository

import pe.lactocolus.mobile.core.common.Reloj
import pe.lactocolus.mobile.core.common.randomUuid
import pe.lactocolus.mobile.db.LactoColusDb
import pe.lactocolus.mobile.domain.model.TipoEntidadSync

/**
 * Appends a record to `cola_sync`. Every device-born write goes through here so the deferred
 * queue is the single path to the plant. Payload is the JSON body the sync engine will send.
 */
class ColaSyncWriter(
    private val db: LactoColusDb,
    private val reloj: Reloj,
) {
    fun encolar(tipo: TipoEntidadSync, entidadId: String, claveIdempotencia: String, payload: String) {
        val ahora = reloj.ahoraMillis()
        db.syncQueries.encolar(
            id_local = randomUuid(),
            tipo_entidad = tipo.name,
            entidad_id = entidadId,
            clave_idempotencia = claveIdempotencia,
            payload = payload,
            creado_en = ahora,
            actualizado_en = ahora,
        )
    }
}
