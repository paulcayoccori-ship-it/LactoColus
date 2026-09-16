# Penalizaciones por calidad y asistencia técnica

## Alcance y seguridad de decisiones
Se generan propuestas auditables desde análisis de calidad. Solo administradores activos gestionan reglas, recálculos, decisiones, anulaciones y asistencias. Registrar calidad puede detectar una propuesta, pero no aprueba sanciones, descuenta dinero ni expulsa productores.

Agua añadida:
- Mayor a cero y menor a 5 %, primera falta: amonestación con tarifa penalizada configurada.
- Mayor a cero y menor a 5 %, segunda o posterior falta: propuestas de pérdida de liquidación y expulsión, ambas con decisión administrativa expresa.
- Igual o mayor a 5 %: sanción grave con tarifa configurada y propuesta de expulsión.
- Cero no produce sanción por agua.

El sistema no elige S/ 0.60 ni S/ 0.70. Tampoco inventa tarifa de primera falta. Reglas inicialmente deshabilitadas: una detección queda `pendiente_configuracion`, sin tarifa aprobable. El administrador debe configurar ambas tarifas, activación, alcance y reincidencia; guardar requiere motivo/referencia de aprobación y crea una versión.

Toda sanción comienza pendiente; incluso la primera falta requiere decisión administrativa antes de tener efecto en una futura liquidación. Aprobar propuesta de expulsión conserva la decisión, pero NO desactiva ni elimina al productor. Una eventual ejecución administrativa del cambio de estado es una acción separada y no automatizada.

## Decisiones configurables
- Alcance de tarifa: entrega vinculada, día de muestra o semana de muestra (jueves–miércoles). Una tarifa con alcance entrega no se puede aprobar si falta esa relación.
- Unidad de falta: cada análisis con agua o cada día con agua.
- Ventana de reincidencia: cantidad de días anteriores a la muestra; NULL usa todo el historial. La ventana se mide hacia atrás desde la fecha/hora de muestra. El modo día cuenta fechas distintas anteriores, no multiplica faltas por muestras del mismo día.
- Reincidencias se calculan sobre análisis no anulados con agua > 0, ordenados por muestra e ID; no dependen del orden en que fueron sincronizados. Son detecciones, sujetas a revisión; no equivalen a una expulsión automática.

La segunda falta propone pérdida de liquidación y no otra tarifa. Las decisiones sobre pérdida/expulsión son independientes: aprobada o rechazada; no se infieren de aprobar la sanción. Rechazarla rechaza sus propuestas. No se permite una decisión sin comentario.

## Historial y recálculo controlado
Cada sanción copia análisis, agua, regla, tarifa, alcance, número de falta y propuestas. La auditoría conserva valores anteriores/nuevos, motivo, actor y fecha. Anular conserva el registro. UUID y análisis únicos impiden duplicar sanciones.

Alta/corrección/revisión/anulación de calidad marca las sanciones del productor como `requiere_revision`; no modifica importes históricos ni aprobaciones silenciosamente. Recalcular es acción administrativa motivada sobre todo el historial. Si la fuente se anuló o ya no contiene agua, anula la sanción con auditoría. Si cambia fuente, tarifa/regla o reincidencia, vuelve a pendiente y exige otra decisión. Si nada relevante cambió, revalida sin duplicar ni sustituir la decisión.

Al integrar liquidaciones (fase 7), solo las sanciones aprobadas y sin revisión pendiente pueden aplicarse; las liquidaciones congeladas deben guardar sus propias copias. Un recálculo posterior nunca puede alterar un comprobante aprobado/pagado: requiere ajustes auditados. Esta fase no registra pagos.

## Acidez y asistencia
Se usa exclusivamente el criterio de acidez copiado en el análisis, activo y vigente tanto en el perfil como en el criterio individual. Acidez fuera de mínimo/máximo genera asistencia técnica, nunca multa automática. Sin criterio aprobado/vigente no se inventa un umbral ni se genera una falsa desviación.

Una asistencia por análisis, estados pendiente, programada, realizada y cancelada. Programar exige responsable activo, fecha futura y observaciones; realizar exige responsable, fecha no futura y observaciones. Todos los cambios exigen motivo y auditoría. La fuente debe estar vigente y revisada. Si se invalida la fuente, el recálculo cancela pendientes/programadas; atenciones realizadas permanecen históricas, marcadas sin fuente vigente. No hay eliminación.

## Panel y API
`/admin/penalizaciones`, menú Penalizaciones y asistencia. Listado paginado con estado, detalle de propuestas, auditoría y asistencias. Formulario de reglas versionadas; evaluación de historial por productor. Botones recuperan estado después de errores y conservan campos; mensajes en español. Relaciones de productor cargadas anticipadamente.

Todas las rutas siguientes requieren Bearer Sanctum de administrador activo:

| Método | Endpoint | Acción |
|---|---|---|
| GET | `/api/v1/penalizaciones?estado=pendiente&page=1` | Listar |
| POST | `/api/v1/penalizaciones/reglas` | Configurar versión aprobada |
| POST | `/api/v1/penalizaciones/productores/{id}/recalcular` | Evaluación controlada con motivo |
| POST | `/api/v1/penalizaciones/{uuid}/decidir` | Decisión y propuestas |
| POST | `/api/v1/penalizaciones/{uuid}/anular` | Anular con motivo |
| GET | `/api/v1/penalizaciones/asistencias` | Asistencias paginadas |
| POST | `/api/v1/penalizaciones/asistencias/{uuid}` | Gestionar asistencia |

Configuración requiere: `activo`, `tarifa_primera`, `tarifa_grave` (decimales positivos en soles/litro), `alcance_tarifa` (`entrega`, `dia`, `semana`), `unidad_falta` (`analisis`, `dia`), `ventana_dias` (entero o NULL) y `motivo`. No se cargan tarifas de ejemplo automáticamente.

Ejemplo de decisión sobre una reincidencia:
```json
{"decision":"aprobada","decision_perdida":"aprobada","decision_expulsion":"rechazada","motivo":"Expediente revisado por administración"}
```
Recálculo/anulación: `{"motivo":"Revisión motivada del expediente"}`.
Asistencia:
```json
{"estado":"programada","responsable_id":1,"fecha_at":"2026-09-20T09:00:00-05:00","observaciones":"Visita coordinada con el productor","motivo":"Programar orientación técnica"}
```
Respuestas de escritura contienen `data` con el registro; recálculo/anulación devuelven mensaje de confirmación. 401 sin token, 403 rol incorrecto, 404 referencia inexistente, 422 tarifa/estado/decisión/fecha/relación inválidos.

## Uso manual
1. Configurar tarifas y reglas realmente aprobadas; no copiar importes sintéticos de los tests.
2. Registrar análisis reales desde Calidad. Las detecciones no equivalen a sanciones aprobadas.
3. Consultar productor y evaluar historial con motivo. Revisar tarifa, alcance y reincidencia.
4. Aprobar/rechazar sanción y decidir expresamente cada propuesta.
5. Gestionar la asistencia cuando exista desviación de acidez.
6. Tras corregir/anular una muestra, recalcular motivadamente y revisar decisiones afectadas.

## Verificación y migración
51 pruebas de penalizaciones + regresión de calidad, 238 aserciones. Concurrencia MySQL exclusiva: una prueba y 7 aserciones; dos evaluaciones simultáneas conservan una sanción pendiente. Cada prueba crea/elimina solo una base aleatoria `lactocolus_test_penalizaciones_<hex>`. SQLite se usa funcionalmente, no como evidencia de bloqueos MySQL.

Migración `2026_09_10_132310_create_penalizaciones_tables`: sanciones/asistencias con referencias restrictivas y análisis únicos. Solo migraciones pendientes, sin demo ni cambios de contraseña. Resultado global y aplicación en `estado-finalizacion.md`.

```sh
php artisan test --compact tests/Feature/PenalizacionesTest.php tests/Feature/CalidadTest.php
LACTOCOLUS_MYSQL_TESTS=1 php artisan test --compact tests/Feature/ConcurrentPenalizacionesMySqlTest.php
```
