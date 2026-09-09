# Recepción en planta y conciliación

## Alcance y decisiones

Una recepción pertenece a una jornada cerrada y conserva la ruta y el recolector de esa jornada. Cada jornada tiene como máximo una recepción vigente; no existe eliminación física. Las recepciones anuladas permanecen disponibles para auditoría y se excluyen de los indicadores.

El SRS disponible no fija un porcentaje de tolerancia. Por eso se añadió la configuración administrable `tolerancia_conciliacion_porcentaje`. Un administrador debe definirla antes de registrar recepciones y cada recepción guarda una copia del valor utilizado. La tolerancia representa el porcentaje absoluto permitido, por lo que se evalúan tanto faltantes como excedentes.

Se permite una lectura externa por recepción. El UUID externo tiene índice único y una repetición devuelve la recepción original con estado `repetida`.

## Cálculo

El servidor suma las entregas de la jornada (`litros_campo`); el navegador nunca puede enviar ese total. Con `litros_planta` medidos se calcula:

```text
diferencia_litros = litros_planta - litros_campo
diferencia_porcentaje = abs(diferencia_litros) / litros_campo * 100
```

Cuando el total de campo es cero se usa 100% para cualquier lectura positiva. Si el porcentaje es menor o igual a la tolerancia, el resultado es `dentro_tolerancia`; en caso contrario es `con_diferencia` y se crea una alerta `pendiente`. Los litros medidos deben ser mayores que cero y se almacenan con tres decimales.

## Alertas y auditoría

Una alerta por recepción muestra litros de campo, litros de planta, diferencia y porcentaje. Sus estados son `pendiente`, `revisada` y `resuelta`; la interfaz de esta entrega permite resolverla con comentario obligatorio. Corregir una recepción recalcula la conciliación y actualiza o resuelve su alerta. Anular exige motivo, registra auditoría y resuelve cualquier alerta pendiente asociada.

Las correcciones y anulaciones guardan valor anterior, valor nuevo, usuario, fecha y motivo en `auditorias_recepcion`. Las entregas originales nunca se modifican.

## Permisos

El panel y la configuración requieren la capacidad `administrar-recepciones` (administrador activo). El endpoint admite administradores activos y recolectores activos; un recolector solo puede enviar o repetir lecturas de jornadas donde es el responsable. Todas las verificaciones se realizan en servidor y las operaciones críticas usan transacciones y bloqueos pesimistas de MySQL.

## Panel

Ruta: `/admin/recepciones`.

El panel permite definir tolerancia, listar y filtrar por fecha, ruta, recolector y resultado, registrar una recepción de jornada cerrada, consultar detalle, corregir con motivo, anular y resolver alertas. El dashboard muestra recepciones del día, litros recibidos hoy y alertas pendientes.

## API

`POST /api/v1/recepciones/lecturas` requiere `Authorization: Bearer <token>` de Sanctum y usuario activo con permiso de operación de campo.

Solicitud:

```json
{
  "jornada_uuid": "uuid-de-la-jornada-cerrada",
  "uuid_lectura_externa": "uuid-generado-por-el-cliente",
  "litros_planta": "125.350",
  "recibida_at": "2026-09-09T12:30:00-05:00",
  "fuente_medicion": "manual",
  "observaciones": "Recepción de turno"
}
```

Una creación responde `201` con `data.estado_sincronizacion=creada`; repetir el UUID responde `200` con `repetida` y el registro existente. Jornada inexistente, abierta, anulada, sin tolerancia o perteneciente a otro recolector devuelve error de validación/autorización sin crear datos.

## Migración y pruebas

La migración `2026_09_09_015614_create_recepciones_conciliacion_tables.php` crea configuración, recepciones, alertas y auditoría con restricciones únicas para jornada y UUID externo. Debe ejecutarse solo como migración pendiente.

La suite cubre jornada cerrada, cálculo positivo y negativo, tolerancia, alertas, corrección y anulación auditadas, permisos, idempotencia, validación y dashboard. `ConcurrentRecepcionesMySqlTest` requiere `LACTOCOLUS_MYSQL_TESTS=1` y crea una base MySQL temporal exclusiva; si no existe un servidor de pruebas, se omite sin tocar la base local. SQLite no demuestra los bloqueos de concurrencia de MySQL.

## Uso manual

1. Ejecutar la migración pendiente.
2. Entrar como administrador en `/admin/recepciones` y definir la tolerancia.
3. Cerrar una jornada desde Acopios.
4. Registrar los litros recibidos y revisar el resultado.
5. Si hay alerta, corregir la lectura con motivo o resolverla con comentario; para retirar la recepción use Anular con motivo.
