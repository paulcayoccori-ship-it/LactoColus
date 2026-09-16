# Comunicados

## Decisiones y alcance
Publicaciones generales, urgentes o de capacitación. Audiencias: todos los productores activos, productores de ruta activa, productores seleccionados o roles internos seleccionados. Texto plano escapado, sin HTML ejecutable. No implementa push ni mobile.

Estados: borrador, programado, publicado, vencido, anulado. Crear guarda un borrador; publicar lo autoriza y lo programa si la fecha es futura. La API comprueba las fechas al leer, de modo que un programador retrasado no expone contenidos futuros ni vencidos. El comando `php artisan comunicados:actualizar` materializa estados y registra auditoría; se agenda cada minuto en `routes/console.php`. En despliegue ejecutar `php artisan schedule:run` cada minuto. Todas las fechas usan la zona de la aplicación.

La audiencia de productores se copia al crear el borrador. Cambiar luego de ruta no altera esa lista histórica. Los roles se guardan por nombre; el acceso interno exige conservar actualmente el rol seleccionado. Los productores inactivos o eliminados no acceden; las referencias y lecturas permanecen. No se amplía automáticamente la audiencia si ingresan nuevos productores.

Solo se corrigen título, texto, tipo y fechas de un borrador, con motivo. La audiencia no se sobrescribe: anular y crear otro para cambiar destinatarios. Una publicación se rectifica mediante nueva publicación y anulación motivada de la anterior. No hay eliminación física. UUID único permite repetir la creación sin duplicar; lectura única por comunicado/usuario. Transacciones y bloqueo administrativo existente serializan acciones y cambios de usuarios.

## Identidad de productores
El padrón previo no relacionaba productores con cuentas. Se añade rol Spatie `productor` y tabla `cuentas_productor`: usuario único, productor único, estado activo. No se crean cuentas ni se cambian contraseñas. El administrador asigna el rol mediante Usuarios y vincula identidades ya verificadas desde Comunicados. Toda vinculación/desactivación exige motivo y auditoría. No se permite reasignar una cuenta o productor a otra identidad; este cambio necesita un procedimiento específico futuro.

La API anterior del padrón mantiene sus contratos y permisos previos, pero el nuevo rol productor sin rol interno no recibe acceso global. La API de comunicados usa exclusivamente la identidad autenticada; ignora IDs de usuario enviados para leer. Un productor solo recibe sus destinatarios y necesita cuenta, vínculo y productor activos. El rol productor no obtiene panel administrativo.

## Permisos y privacidad
Solo administradores activos crean, corrigen, publican, anulan y vinculan cuentas. Cada acción Livewire y caso de uso reautoriza en servidor. Sanctum y estado activo protegen todas las rutas API. Los usuarios solo consultan comunicados destinados a ellos. UUID ajeno, borrador, futuro, vencido o anulado devuelve 404. La respuesta de destinatario no incluye nombres, IDs, DNI, teléfonos ni listas de otros destinatarios, autor o lecturas ajenas. La consulta de lecturas con nombres queda exclusivamente en el panel administrativo.

## Panel y uso
`/admin/comunicados`, menú Comunicados.
1. Crear borrador con contenido, tipo, audiencia y fechas.
2. Consultar y verificar la audiencia guardada; corregir errores con motivo.
3. Publicar o programar. Una fecha pasada publica inmediatamente; una futura queda programada.
4. Consultar lecturas e historial. Anular requiere motivo y conserva todos los registros.
5. Para cuentas de productores, crear una cuenta real en Usuarios, asignar rol productor y vincular con el padrón mediante comprobación de identidad.

Listado paginado y búsqueda por título, carga anticipada de autor, formularios con errores españoles y botones que recuperan estado mediante la petición Livewire. No utiliza referencias DOM `$refs` ni bloqueo manual de Guardar.

## API v1
Enviar `Authorization: Bearer <token>` y `Accept: application/json`.

| Método | Endpoint | Acceso |
|---|---|---|
| GET | `/api/v1/comunicados?page=1` | Buzón propio paginado |
| GET | `/api/v1/comunicados/{uuid}` | Contenido destinado al usuario |
| POST | `/api/v1/comunicados/{uuid}/lectura` | Lectura propia idempotente |
| POST | `/api/v1/comunicados` | Administrador: crear |
| PUT | `/api/v1/comunicados/{uuid}` | Administrador: corregir borrador con motivo |
| POST | `/api/v1/comunicados/{uuid}/publicar` | Administrador: publicar/programar |
| POST | `/api/v1/comunicados/{uuid}/anular` | Administrador: anular con motivo |
| POST | `/api/v1/comunicados/cuentas/vincular` | Administrador: vincular/desactivar identidad |

Ejemplo de creación:
```json
{"uuid":"3f8ef110-460f-452b-9724-e96dfae61b6c","titulo":"Capacitación de acopio","contenido":"Reunión en planta para revisar el procedimiento.","tipo":"capacitacion","audiencia":"roles","roles":["recolector"],"publicar_at":"2026-09-10T08:00:00-05:00","vence_at":"2026-09-12T18:00:00-05:00"}
```
Otras audiencias: `todos_productores`; `ruta` con `ruta_id`; `seleccionados` con `productores:[1,2]` (IDs reales activos).

Respuesta 201 o 200 repetido: `data` con uuid, título, contenido, tipo, publicar_at, vence_at, estado, leida_at. GET devuelve esa misma estructura sin destinatarios. La lectura responde `{"message":"Lectura registrada."}`; repetir conserva la fecha original. El listado incluye metadatos de paginación y `leida_at` de la cuenta actual.

Anulación: `{"motivo":"Contenido sustituido por una nueva circular"}`.
Vinculación: `{"usuario_id":1,"productor_id":2,"activa":true,"motivo":"Identidad verificada presencialmente"}`. IDs de ejemplo, sin creación automática.

401 sin autenticación, 403 sin permiso, 404 no disponible para esa cuenta, 422 errores de campos, fechas, audiencia o conflicto de identidad. No hay endpoints públicos ni eliminación.

## Pruebas y migración
`ComunicadosTest` + regresión `ProductoresTest`: 32 pruebas, 199 aserciones. Cobertura de roles/audiencias, privacidad, identidad, productor inactivo, conservación tras cambio de ruta, programación/vencimiento con reloj controlado, idempotencia, corrección/anulación auditadas, validación y recuperación del formulario/escape HTML.

`ConcurrentComunicadosMySqlTest`: 1 prueba, 6 aserciones. Dos procesos marcan la misma lectura y conservan un registro. Crea y elimina solo una base aleatoria `lactocolus_test_comunicados_<hex>`. Las pruebas funcionales SQLite no se presentan como demostración de bloqueos MySQL.

Migración `2026_09_09_141320_create_comunicados_tables`: comunicados, destinatarios, lecturas, cuentas de productor y rol productor, sin usuarios de demostración. Aplicar solo migraciones pendientes. Resultados globales y aplicación local en `estado-finalizacion.md`.

```sh
php artisan test --compact tests/Feature/ComunicadosTest.php tests/Feature/ProductoresTest.php
LACTOCOLUS_MYSQL_TESTS=1 php artisan test --compact tests/Feature/ConcurrentComunicadosMySqlTest.php
php artisan comunicados:actualizar
```
