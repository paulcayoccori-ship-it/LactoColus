package pe.lactocolus.mobile.data.local

import pe.lactocolus.mobile.domain.model.Analisis
import pe.lactocolus.mobile.domain.model.ColaSyncItem
import pe.lactocolus.mobile.domain.model.Comunicado
import pe.lactocolus.mobile.domain.model.Entrega
import pe.lactocolus.mobile.domain.model.EntregaCalidad
import pe.lactocolus.mobile.domain.model.EstadoJornada
import pe.lactocolus.mobile.domain.model.EstadoSync
import pe.lactocolus.mobile.domain.model.EstadoTraslado
import pe.lactocolus.mobile.domain.model.Jornada
import pe.lactocolus.mobile.domain.model.JornadaCalidad
import pe.lactocolus.mobile.domain.model.Liquidacion
import pe.lactocolus.mobile.domain.model.ParametroAnalisis
import pe.lactocolus.mobile.domain.model.Productor
import pe.lactocolus.mobile.domain.model.RankingEntry
import pe.lactocolus.mobile.domain.model.ResultadoAnalisis
import pe.lactocolus.mobile.domain.model.Rol
import pe.lactocolus.mobile.domain.model.Ruta
import pe.lactocolus.mobile.domain.model.SolicitudTraslado
import pe.lactocolus.mobile.domain.model.TipoComunicado
import pe.lactocolus.mobile.domain.model.TipoEntidadSync
import pe.lactocolus.mobile.domain.model.Usuario
import pe.lactocolus.mobile.db.Analisis as DbAnalisis
import pe.lactocolus.mobile.db.Cola_sync as DbColaSync
import pe.lactocolus.mobile.db.Comunicado as DbComunicado
import pe.lactocolus.mobile.db.Entrega as DbEntrega
import pe.lactocolus.mobile.db.Entrega_calidad as DbEntregaCalidad
import pe.lactocolus.mobile.db.Jornada as DbJornada
import pe.lactocolus.mobile.db.JornadasDeHoy as DbJornadaCalidad
import pe.lactocolus.mobile.db.Liquidacion as DbLiquidacion
import pe.lactocolus.mobile.db.Parametro_analisis as DbParametro
import pe.lactocolus.mobile.db.Productor as DbProductor
import pe.lactocolus.mobile.db.Ranking as DbRanking
import pe.lactocolus.mobile.db.Ruta as DbRuta
import pe.lactocolus.mobile.db.Solicitud_traslado as DbTraslado
import pe.lactocolus.mobile.db.Usuario as DbUsuario

internal fun Long.toBool(): Boolean = this != 0L
internal fun Boolean.toLong(): Long = if (this) 1L else 0L

internal fun DbUsuario.toDomain() = Usuario(
    idLocal = id_local,
    idRemoto = id_remoto,
    nombre = nombre,
    correo = correo,
    rol = runCatching { Rol.valueOf(rol) }.getOrDefault(Rol.SIN_ACCESO),
    token = token,
    vigenteHasta = vigente_hasta,
)

internal fun DbRuta.toDomain() = Ruta(
    idLocal = id_local,
    idRemoto = id_remoto,
    codigo = codigo,
    nombre = nombre,
    turno = turno,
    vuelta = vuelta.toInt(),
    estado = estado,
    totalProductores = total_productores.toInt(),
)

internal fun DbProductor.toDomain() = Productor(
    idLocal = id_local,
    idRemoto = id_remoto,
    codigo = codigo,
    nombres = nombres,
    apellidos = apellidos,
    rutaId = ruta_id,
    ordenVisita = orden_visita.toInt(),
    activo = activo.toBool(),
    promedioLitros = promedio_litros,
)

internal fun DbJornada.toDomain() = Jornada(
    idLocal = id_local,
    idRemoto = id_remoto,
    uuidPublico = uuid_publico,
    rutaId = ruta_id,
    turno = turno,
    vuelta = vuelta.toInt(),
    fechaOperativa = fecha_operativa,
    estado = EstadoJornada.from(estado),
    observaciones = observaciones,
    totalLitros = total_litros,
    totalEntregas = total_entregas.toInt(),
    abiertaEn = abierta_en,
    cerradaEn = cerrada_en,
    estadoSync = EstadoSync.fromDb(estado_sync, null),
)

internal fun DbEntrega.toDomain() = Entrega(
    idLocal = id_local,
    idRemoto = id_remoto,
    jornadaId = jornada_id,
    productorId = productor_id,
    litros = litros,
    noEntrego = no_entrego.toBool(),
    recolectadaEn = recolectada_en,
    observacion = observacion,
    litrosAnterior = litros_anterior,
    motivoCorreccion = motivo_correccion,
    corregidaEn = corregida_en,
    estadoSync = EstadoSync.fromDb(estado_sync, sync_mensaje),
    intentos = sync_intentos.toInt(),
)

internal fun DbEntregaCalidad.toDomain() = EntregaCalidad(
    idRemoto = id_remoto,
    jornadaIdRemoto = jornada_id_remoto,
    productorId = productor_id,
    productorCodigo = productor_codigo,
    productorNombres = productor_nombres,
    productorApellidos = productor_apellidos,
    litros = litros,
    recolectadaAt = recolectada_at,
    tieneAnalisis = tiene_analisis.toBool(),
)

internal fun DbJornadaCalidad.toDomain() = JornadaCalidad(
    idRemoto = id_remoto,
    uuidPublico = uuid_publico,
    rutaId = ruta_id,
    rutaCodigo = ruta_codigo,
    ruta = ruta,
    recolectorId = recolector_id,
    recolector = recolector,
    fechaOperativa = fecha_operativa,
    turno = turno,
    estado = EstadoJornada.from(estado),
    litros = litros,
    cantidadEntregas = cantidad_entregas.toInt(),
    analizadas = analizadas.toInt(),
)

internal fun DbAnalisis.toDomain(parametros: List<ParametroAnalisis>) = Analisis(
    idLocal = id_local,
    idRemoto = id_remoto,
    uuidPublico = uuid_publico,
    productorId = productor_id,
    fecha = fecha,
    equipo = equipo,
    fuente = fuente,
    resultado = ResultadoAnalisis.from(resultado),
    aguaAnadida = agua_anadida,
    observaciones = observaciones,
    parametros = parametros,
    estadoSync = EstadoSync.fromDb(estado_sync, sync_mensaje),
)

internal fun DbParametro.toDomain() = ParametroAnalisis(
    clave = clave,
    valor = valor,
    unidad = unidad,
    dentroDeRango = dentro_de_rango?.toBool(),
    limiteMin = limite_min,
    limiteMax = limite_max,
)

internal fun DbComunicado.toDomain() = Comunicado(
    idLocal = id_local,
    idRemoto = id_remoto,
    tipo = TipoComunicado.from(tipo),
    titulo = titulo,
    cuerpo = cuerpo,
    fecha = fecha,
    leido = leido.toBool(),
)

internal fun DbLiquidacion.toDomain() = Liquidacion(
    idLocal = id_local,
    semana = semana,
    desde = desde,
    hasta = hasta,
    litros = litros,
    precioLitro = precio_litro,
    bonos = bonos,
    penalizaciones = penalizaciones,
    descuentos = descuentos,
    total = total,
    pagada = estado_pago.equals("pagada", ignoreCase = true),
)

internal fun DbRanking.toDomain() = RankingEntry(
    periodo = periodo,
    fecha = fecha,
    posicion = posicion.toInt(),
    productor = productor,
    ruta = ruta,
    puntuacion = puntuacion,
)

internal fun DbTraslado.toDomain() = SolicitudTraslado(
    idLocal = id_local,
    idRemoto = id_remoto,
    rutaOrigen = ruta_origen,
    rutaDestino = ruta_destino,
    fechaDeseada = fecha_deseada,
    motivo = motivo,
    estado = EstadoTraslado.from(estado),
    anticipacionDias = anticipacion_dias.toInt(),
    creadaEn = creada_en,
    estadoSync = EstadoSync.fromDb(estado_sync, sync_mensaje),
)

internal fun DbColaSync.toDomain() = ColaSyncItem(
    idLocal = id_local,
    tipoEntidad = runCatching { TipoEntidadSync.valueOf(tipo_entidad) }.getOrDefault(TipoEntidadSync.ENTREGA),
    entidadId = entidad_id,
    claveIdempotencia = clave_idempotencia,
    payload = payload,
    intentos = intentos.toInt(),
    ultimoError = ultimo_error,
    estado = EstadoSync.fromDb(estado, ultimo_error),
    creadoEn = creado_en,
    actualizadoEn = actualizado_en,
)
