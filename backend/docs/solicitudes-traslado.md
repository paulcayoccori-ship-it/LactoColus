# Solicitudes de traslado

## Alcance y decisiones
Productores con cuenta vinculada pueden solicitar un cambio de ruta por API; administradores pueden registrarlo y decidir desde el panel. Estados: pendiente, aprobada, rechazada, aplicada, cancelada. UUID único para reintentos. No se elimina ninguna solicitud ni historial.

El plazo ambiguo de 2 o 3 días no se fija automáticamente. Configuración versionada `traslados.anticipacion_dias`, inicialmente sin definir: se exige al administrador configurar un entero de 1–365 días calendario (capacidad de configuración, no límite regulatorio). Cada solicitud copia la versión y el plazo. La fecha efectiva debe ser al menos hoy + plazo en la zona horaria de la aplicación. Cambiar la regla no altera solicitudes previas.

Aprobar no mueve al productor. La aplicación requiere aprobación y fecha efectiva alcanzada. Una aprobación pendiente cuya fecha ya pasó debe rechazarse y volver a solicitarse; una solicitud aprobada a tiempo puede aplicarse tarde si el programador estuvo detenido. Se registra fecha/hora real de aplicación, sin fingir ejecución retroactiva.

## Relaciones, conflictos y concurrencia
La ruta actual se obtiene del servidor. El destino debe ser otra ruta activa; el productor debe estar activo y no eliminado. Solo una solicitud pendiente o aprobada por productor. Una columna generada `productor_pendiente` con índice único en MySQL protege esa exclusividad incluso ante carreras; UUID también único.

Cada escritura usa transacción y el bloqueo administrativo compartido con rutas/usuarios, además de bloqueos de productor, solicitud, rutas y asignación. Al aplicar se vuelve a comprobar que el productor conserva la ruta capturada y el destino sigue activo. Un cambio genera conflicto explícito: no se reemplaza la asignación. El administrador debe revisar y cancelar la solicitud, y crear otra si corresponde.

La aplicación actualiza una única fila `ruta_productor`; no borra la asignación operativa ni genera una segunda. Incorpora al final del destino y compacta el orden de la ruta anterior usando el repositorio existente. `historial_traslados` conserva ruta anterior/nueva, orden anterior/nuevo, usuario, solicitud y fecha de ejecución. Clave única por solicitud asegura una sola aplicación histórica. Acopios, calidad y demás históricos no se alteran.

Aprobación/rechazo exigen comentario, usuario y fecha. Cancelar exige motivo y solo se admite antes de aplicar. Repetir una operación ya aplicada/decidida no duplica sus efectos; cambiar una decisión final necesita nueva solicitud. Toda acción deja auditoría. Las solicitudes rechazadas/canceladas liberan el cupo para una nueva solicitud, conservándose.

## Automatización
`php artisan traslados:aplicar` procesa aprobadas cuya fecha llegó; registrado cada minuto en el scheduler con `withoutOverlapping`. Ejecutar `php artisan schedule:run` cada minuto en despliegue. La ejecución automática utiliza la autorización previamente registrada; la auditoría distingue `aplicacion_automatica` e identifica al aprobador original. No constituye una nueva aprobación ni acceso de campo.

Un conflicto deja la solicitud aprobada con `ultimo_error` visible y registra auditoría una vez por mensaje distinto; puede cancelarse desde el panel. No hay transferencia silenciosa ni reintento que ignore validaciones. El comando es idempotente y permite procesar después de una interrupción.

## Permisos y privacidad
Gate `administrar-traslados`: solo administrador activo. Cada acción Livewire y caso de uso valida autorización. API protegida con Sanctum y usuario activo. Productor requiere identidad activa en `cuentas_productor`, solo puede solicitar, consultar y cancelar lo suyo. Enviar otro productor se rechaza; UUID ajeno devuelve 404. Recolector, calidad, contador y supervisor no gestionan traslados. No se expone el padrón global al rol productor.

## Panel y pasos
`/admin/traslados`, menú Solicitudes de traslado.
1. Definir anticipación y motivo en Configuración.
2. Registrar solicitud para productor con ruta actual, destino, fecha y motivo; alternativamente el productor utiliza la API.
3. Consultar solicitud y aprobar/rechazar con comentario.
4. Llegada la fecha, aplicar manualmente o dejar ejecutar el programador.
5. Verificar destino y orden en Rutas, y el historial en la solicitud.
6. Ante conflicto, revisar la asignación actual y cancelar motivadamente; conservar el registro anterior.

Listado paginado y filtro por estado; relaciones anticipadas evitan N+1. Formularios muestran errores españoles, conservan datos y liberan botones tras fallos.

## API
Autenticación Bearer Sanctum, `Accept: application/json`.

| Método | Endpoint | Acceso |
|---|---|---|
| GET | `/api/v1/traslados?estado=pendiente&page=1` | Administrador: todos; productor: propios |
| GET | `/api/v1/traslados/opciones` | Rutas activas y productores permitidos, regla actual |
| POST | `/api/v1/traslados` | Solicitar (administrador o productor propio) |
| GET | `/api/v1/traslados/{uuid}` | Consultar dentro del alcance |
| POST | `/api/v1/traslados/{uuid}/aprobar` | Administrador, comentario |
| POST | `/api/v1/traslados/{uuid}/rechazar` | Administrador, comentario |
| POST | `/api/v1/traslados/{uuid}/cancelar` | Administrador o propietario, motivo |
| POST | `/api/v1/traslados/{uuid}/aplicar` | Administrador |
| POST | `/api/v1/traslados/configuracion` | Administrador, nueva versión |

Configuración (ejemplo, no valor automático):
```json
{"anticipacion_dias":3,"motivo":"Plazo aprobado para organizar recorridos"}
```
Solicitud:
```json
{"uuid":"4b574ac5-df9f-4d17-8437-1aa85aa9493d","productor_id":1,"ruta_solicitada_id":2,"fecha_efectiva":"2026-09-20","motivo":"Cambio de domicilio"}
```
El productor puede omitir `productor_id`; se resuelve desde su cuenta. La administración debe indicarlo. La ruta actual, estado, solicitante y fechas de auditoría se obtienen en servidor.

Respuesta 201 (`data`) con uuid, productor_id, ruta_actual_id, ruta_solicitada_id, solicitada_at, fecha_efectiva, motivo, estado `pendiente`, comentario, decidida_at, aplicada_at, regla_aplicada y advertencia. UUID repetido responde 200 sin duplicar. Aprobar/rechazar: `{"comentario":"Evaluado y autorizado"}`; cancelar: `{"motivo":"Desistimiento del solicitante"}`.

Errores: 401 sin token, 403 sin rol/identidad, 404 registro ajeno/inexistente, 422 fechas/plazo sin configurar/destino inválido/solicitud concurrente/estado o asignación cambiada. No hay DELETE.

## Verificación y migración
45 pruebas de traslados y regresión de rutas, 264 aserciones. Dos escenarios MySQL exclusivo, 17 aserciones: solicitudes distintas simultáneas para un productor y aplicaciones repetidas simultáneas. Base aleatoria `lactocolus_test_traslados_<hex>` creada y eliminada por cada escenario. No toca datos locales ni atribuye semántica MySQL a SQLite.

Migración `2026_09_09_193330_create_traslados_tables` crea solicitudes e historial, claves restrictivas e índices únicos. Solo ejecutar migraciones pendientes. Resultado global y aplicación en `estado-finalizacion.md`.

```sh
php artisan test --compact tests/Feature/TrasladosTest.php tests/Feature/RutasAcopioTest.php
LACTOCOLUS_MYSQL_TESTS=1 php artisan test --compact tests/Feature/ConcurrentTrasladosMySqlTest.php
php artisan traslados:aplicar
```
