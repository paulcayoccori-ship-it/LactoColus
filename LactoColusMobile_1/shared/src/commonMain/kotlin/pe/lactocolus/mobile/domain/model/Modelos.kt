package pe.lactocolus.mobile.domain.model

/**
 * Domain entities and value objects. Pure Kotlin: no Android, Compose, SQLDelight or Ktor.
 * Money is [pe.lactocolus.mobile.core.common] free — amounts are plain [Double] in PEN and
 * litres in litros; presentation formats them.
 */

enum class Rol { RECOLECTOR, CALIDAD, PRODUCTOR, SIN_ACCESO;
    companion object {
        /**
         * A backend account can hold several Spatie roles at once (e.g. `["administrador",
         * "recolector"]`); the mobile app only has flows for three of them, so this picks the
         * highest-priority match — recolector, then calidad, then productor. Anything else
         * (administrador/supervisor/contador only, an empty list, or role names the app
         * doesn't recognise) resolves to [SIN_ACCESO] instead of guessing a default: that
         * account has no mobile flow.
         */
        fun resolverDesdeRoles(roles: List<String>): Rol {
            val normalizados = roles.map { it.trim().lowercase() }.toSet()
            return when {
                "recolector" in normalizados -> RECOLECTOR
                "calidad" in normalizados -> CALIDAD
                "productor" in normalizados -> PRODUCTOR
                else -> SIN_ACCESO
            }
        }
    }
}

/** Per-record synchronisation state. [RECHAZADO] carries the server message. */
sealed interface EstadoSync {
    data object Pendiente : EstadoSync
    data object Enviando : EstadoSync
    data object Creado : EstadoSync
    data object Repetido : EstadoSync
    data class Rechazado(val mensaje: String) : EstadoSync

    companion object {
        fun fromDb(value: String, mensaje: String?): EstadoSync = when (value) {
            "ENVIANDO" -> Enviando
            "CREADO" -> Creado
            "REPETIDO" -> Repetido
            "RECHAZADO" -> Rechazado(mensaje ?: "Rechazado por el servidor")
            else -> Pendiente
        }
    }

    val clave: String
        get() = when (this) {
            Pendiente -> "PENDIENTE"
            Enviando -> "ENVIANDO"
            Creado -> "CREADO"
            Repetido -> "REPETIDO"
            is Rechazado -> "RECHAZADO"
        }
}

data class Usuario(
    val idLocal: String,
    val idRemoto: Long?,
    val nombre: String,
    val correo: String,
    val rol: Rol,
    val token: String,
    val vigenteHasta: Long,
)

data class Ruta(
    val idLocal: String,
    val idRemoto: Long?,
    val codigo: String,
    val nombre: String,
    val turno: String,
    val vuelta: Int,
    val estado: String,
    val totalProductores: Int,
)

data class Productor(
    val idLocal: String,
    val idRemoto: Long?,
    val codigo: String,
    val nombres: String,
    val apellidos: String,
    val rutaId: String?,
    val ordenVisita: Int,
    val activo: Boolean,
    val promedioLitros: Double,
)

enum class EstadoJornada { ABIERTA, CERRADA, ANULADA;
    companion object { fun from(v: String) = entries.firstOrNull { it.name.equals(v, true) } ?: ABIERTA }
}

data class Jornada(
    val idLocal: String,
    val idRemoto: Long?,
    val uuidPublico: String?,
    val rutaId: String,
    val turno: String,
    val vuelta: Int,
    val fechaOperativa: String,
    val estado: EstadoJornada,
    val observaciones: String?,
    val totalLitros: Double,
    val totalEntregas: Int,
    val abiertaEn: Long,
    val cerradaEn: Long?,
    val estadoSync: EstadoSync,
)

data class Entrega(
    val idLocal: String,
    val idRemoto: Long?,
    val jornadaId: String,
    val productorId: String,
    val litros: Double?,
    val noEntrego: Boolean,
    val recolectadaEn: Long,
    val observacion: String?,
    val litrosAnterior: Double?,
    val motivoCorreccion: String?,
    val corregidaEn: Long?,
    val estadoSync: EstadoSync,
    val intentos: Int,
)

enum class ResultadoAnalisis { PENDIENTE_REVISION, CONFORME, OBSERVADO;
    companion object { fun from(v: String) = entries.firstOrNull { it.name.equals(v, true) } ?: PENDIENTE_REVISION }
}

data class ParametroAnalisis(
    val clave: String,
    val valor: Double,
    val unidad: String,
    val dentroDeRango: Boolean?,
    val limiteMin: Double?,
    val limiteMax: Double?,
)

data class Analisis(
    val idLocal: String,
    val idRemoto: Long?,
    val uuidPublico: String?,
    val productorId: String,
    val fecha: Long,
    val equipo: String?,
    val fuente: String,
    val resultado: ResultadoAnalisis,
    val aguaAnadida: Double,
    val observaciones: String?,
    val parametros: List<ParametroAnalisis>,
    val estadoSync: EstadoSync,
) {
    val tieneAguaAnadida: Boolean get() = aguaAnadida > 0.0
}

enum class TipoComunicado { GENERAL, URGENTE, CAPACITACION;
    companion object { fun from(v: String) = entries.firstOrNull { it.name.equals(v, true) } ?: GENERAL }
}

data class Comunicado(
    val idLocal: String,
    val idRemoto: Long?,
    val tipo: TipoComunicado,
    val titulo: String,
    val cuerpo: String,
    val fecha: Long,
    val leido: Boolean,
)

data class Liquidacion(
    val idLocal: String,
    val semana: String,
    val desde: String,
    val hasta: String,
    val litros: Double,
    val precioLitro: Double,
    val bonos: Double,
    val penalizaciones: Double,
    val descuentos: Double,
    val total: Double,
    val pagada: Boolean,
)

data class RankingEntry(
    val periodo: String,
    val fecha: String,
    val posicion: Int,
    val productor: String,
    val ruta: String,
    val puntuacion: Double,
)

enum class EstadoTraslado { PENDIENTE, APROBADA, RECHAZADA, APLICADA, CANCELADA;
    companion object { fun from(v: String) = entries.firstOrNull { it.name.equals(v, true) } ?: PENDIENTE }
}

data class SolicitudTraslado(
    val idLocal: String,
    val idRemoto: Long?,
    val rutaOrigen: String,
    val rutaDestino: String,
    val fechaDeseada: String,
    val motivo: String,
    val estado: EstadoTraslado,
    val anticipacionDias: Int,
    val creadaEn: Long,
    val estadoSync: EstadoSync,
)

enum class TipoEntidadSync { ENTREGA, ANALISIS, JORNADA, TRASLADO, LECTURA_COMUNICADO }

data class ColaSyncItem(
    val idLocal: String,
    val tipoEntidad: TipoEntidadSync,
    val entidadId: String,
    val claveIdempotencia: String,
    val payload: String,
    val intentos: Int,
    val ultimoError: String?,
    val estado: EstadoSync,
    val creadoEn: Long,
    val actualizadoEn: Long,
)
