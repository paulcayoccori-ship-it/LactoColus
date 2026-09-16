# Periodos y liquidaciones semanales

## Alcance y decisiones
Periodos de jueves a miércoles; pago previsto el viernes siguiente. Administrador crea, configura, cierra, aprueba y anula. Contador calcula, consulta, solicita ajustes y registra pagos. Ninguna de estas funciones concede acceso a usuarios, rutas o calidad. Productor con cuenta activa vinculada consulta por API exclusivamente sus liquidaciones aprobadas o pagadas.

Precio inicial S/ 1.70 por litro según el requerimiento del usuario. Versiones en `reglas_operativas`, clave `liquidaciones`; cada cierre conserva una copia. No se localizó el SRS completo: no se afirma haberlo consultado. No se agregan tarifas ni fórmulas científicas ausentes.

## Cierre y cálculo
- No se permiten periodos vigentes superpuestos. El cierre ocurre después de finalizar el miércoles; exige resolver jornadas abiertas y sanciones pendientes de los productores incluidos.
- Se copian entregas de jornadas cerradas por fecha local de recolección, compras de queso confirmadas pendientes de descuento y sanciones aprobadas relevantes. Se conserva identificación histórica del productor.
- Acopios y ventas usan el bloqueo administrativo compartido con el cierre. No se permiten nuevas fuentes con fecha dentro de un periodo cerrado ni modificaciones de ventas vinculadas. Una sincronización repetida conserva su idempotencia.
- Las copias financieras son inmutables. Corregir posteriormente un análisis de calidad no modifica una liquidación cerrada: requiere ajuste administrativo documentado.
- Cada productor recibe siete totales diarios y total semanal. Cálculo con BCMath: litros a tres decimales, precio a dos; productos intermedios a cinco y redondeo monetario final a dos decimales (mitad hacia arriba).
- Se aplican únicamente tarifas penalizadas aprobadas en su alcance copiado: entrega, día o semana. Coincidencias con distintas tarifas bloquean por defecto; un administrador puede aprobar una versión que elija la menor. Una tarifa penalizada superior a la estándar requiere revisión.
- Pérdida de liquidación requiere decisión administrativa aprobada. Su alcance sobre bonos debe configurarse expresamente; no se infiere. Propuestas de expulsión no cambian el estado del productor.
- Total = bruto − penalizaciones − compras de queso + bonos aprobados − bonos retenidos + ajustes aprobados. Un saldo negativo no puede aprobarse ni pagarse; requiere ajuste motivado. No se crea deuda trasladada automáticamente.

## Ajustes, aprobación y pago
Cada ajuste tiene UUID, tipo bono/ajuste, importe, solicitante y motivo. El administrador aprueba o rechaza con comentario. No hay edición silenciosa. Ajustes pendientes bloquean aprobación y pago. Puede ajustarse una liquidación aprobada aún no pagada sin cambiar su cálculo base.

Una liquidación pagada no puede recalcularse ni anularse. Una corrección posterior se registra en otra liquidación no pagada del mismo productor, indicando `origen_uuid` de la anterior pagada. El periodo receptor debe ser posterior.

Pago completo, importe calculado por servidor, UUID externo único y una sola fila por liquidación. Repetir UUID devuelve el pago existente; otro UUID no paga dos veces. Guarda método, fecha, usuario y copia integral del resultado. Compras descontadas pasan a pagadas mediante esa liquidación y no generan otra salida de inventario.

Anulación de periodo exige motivo y ausencia de pagos. Conserva liquidaciones, fuentes y auditoría; libera el vínculo financiero de ventas para una nueva liquidación. No hay eliminación física.

## Panel y API
Panel: `/admin/liquidaciones`. Comprobante imprimible: `/admin/liquidaciones/{uuid}/comprobante`. PDF: `/admin/liquidaciones/{uuid}/pdf`.

Todas las siguientes rutas están bajo Sanctum y controles de usuario activo:

| Método | Ruta | Acceso |
|---|---|---|
| GET | `/api/v1/liquidaciones` | Administrador/contador; productor solo propias |
| GET | `/api/v1/liquidaciones/{uuid}` | Igual, con aislamiento por productor |
| GET | `/api/v1/liquidaciones/{uuid}/comprobante.pdf` | Igual |
| GET | `/api/v1/liquidaciones/periodos` | Administrador/contador |
| POST | `/api/v1/liquidaciones/periodos` | Administrador |
| POST | `/api/v1/liquidaciones/reglas` | Administrador |
| POST | `/api/v1/liquidaciones/periodos/{uuid}/cerrar` | Administrador |
| POST | `/api/v1/liquidaciones/periodos/{uuid}/calcular` | Administrador/contador |
| POST | `/api/v1/liquidaciones/periodos/{uuid}/aprobar` | Administrador |
| POST | `/api/v1/liquidaciones/periodos/{uuid}/anular` | Administrador |
| POST | `/api/v1/liquidaciones/{uuid}/ajustes` | Administrador/contador |
| POST | `/api/v1/liquidaciones/ajustes/{uuid}/decidir` | Administrador |
| POST | `/api/v1/liquidaciones/{uuid}/pagar` | Administrador/contador |

Crear periodo:
```json
{"uuid":"9360a3a3-02aa-42d0-a37a-2879b940130e","desde":"2026-09-03","motivo":"Apertura semanal"}
```
Cerrar/calcular/aprobar/anular: `{"motivo":"Revisión documentada"}`.

Ajuste:
```json
{"uuid":"17ff3ec9-5a90-48ee-ad9d-7ce1ca0a7f34","tipo":"ajuste","importe":"-2.50","motivo":"Corrección documentada","origen_uuid":null}
```
Decidir: `{"decision":"aprobado","motivo":"Importe verificado"}` (o `rechazado`).

Pago:
```json
{"uuid_externo":"977b7eb4-ffbc-43c5-ac3c-4a867742fa4f","metodo":"Transferencia","pagado_at":"2026-09-11 10:00:00"}
```
No enviar total: el servidor lo calcula. Fecha no futura ni anterior a aprobación. Respuesta contiene UUID externo, importe decimal como cadena, método y fecha. Consultas de liquidación usan envoltorio `data`, datos históricos, litros diarios, total base, totales efectivos, ajustes y pago. Errores: 401 sin sesión/token, 403 rol no autorizado, 404 UUID inexistente o ajeno, 422 validación/conflicto.

## Uso manual
1. Ingresar como administrador, revisar tarifa y reglas aplicables.
2. Crear un periodo cuyo inicio sea jueves.
3. Cerrar jornadas y revisar sanciones/compras de la semana; cerrar el periodo una vez terminado.
4. Calcular como contador o administrador. Revisar litros diarios, descuentos y total.
5. Solicitar ajustes cuando correspondan; aprobarlos/rechazarlos como administrador.
6. Aprobar el periodo y registrar pago como contador. Descargar comprobante.
7. Repetir el UUID de pago: debe devolver el mismo registro. Probar acceso de otro productor: debe denegarse sin revelar datos.

## Implementación y verificación
Migración `2026_09_10_142920_create_liquidaciones_tables`: periodos, liquidaciones, copias de productores/entregas/sanciones/ventas, ajustes y pagos. Índices únicos para semana vigente, liquidación/productor, venta vinculada vigente, UUID y pago único. Todos los cambios operativos usan transacción y bloqueo compartido; no hay borrado.

`LiquidacionesTest` cubre calendario, exactitud, copia de tarifa, aprobación, saldo negativo, ajustes, pago repetido, historial, permisos, aislamiento, recuperación Livewire, PDF e indicadores. `ConcurrentLiquidacionesMySqlTest` ejecuta dos procesos con barrera, mismos identificadores y base MySQL aleatoria exclusiva; verifica un solo pago. No utiliza la base local ni SQLite para demostrar bloqueos MySQL.

PDF generado sin dependencias nuevas: documento interno paginado con marca vectorial LactoColus y texto Windows-1252. No es comprobante tributario ni factura electrónica; caracteres fuera de ese alfabeto se transliteran. La vista imprimible conserva Unicode. No hay integración bancaria ni pago automático.
