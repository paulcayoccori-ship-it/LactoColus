# Cuarta entrega: registro digital de acopio

Esta entrega añade el registro web y la API de jornadas y entregas de leche. Se revisaron las reglas del proyecto, Graphify, `docs/rutas-acopio.md` y todos los documentos del repositorio; no existe otro SRS de acopios disponible en el proyecto. No se usaron adjuntos externos.

## Decisiones provisionales

- Una jornada representa una ejecución de una ruta en una fecha operativa y un turno. Los turnos disponibles son `primera_vuelta` y `segunda_vuelta`.
- Los estados son `abierta`, `cerrada` y `anulada`. Solo una jornada abierta admite entregas y correcciones. Cerrar o anular conserva jornadas y entregas; no existe eliminación física.
- Una ruta no puede tener dos jornadas del mismo turno en la misma fecha. La restricción única también protege solicitudes simultáneas.
- Al crear una jornada se copia el recolector asignado en ese momento. Si después cambia la ruta, la jornada conserva al responsable histórico.
- Se permite una sola entrega por productor dentro de cada jornada. El UUID generado por el cliente también es único. Repetirlo devuelve la entrega existente como `repetido`; nunca se reemplaza silenciosamente.
- Los productores de una entrega deben pertenecer a la ruta de la jornada al momento de registrar. La entrega guarda `ruta_id`, `productor_id` y `recolector_id` históricos, aunque luego cambien de ruta o estado.
- Los litros son decimales con tres posiciones, mayores que cero y hasta `9999999.999`.
- Las correcciones administrativas solo son posibles mientras la jornada está abierta. Cada corrección guarda valor anterior, valor nuevo, usuario, fecha y motivo. Cierres y anulaciones también se auditan; la anulación exige motivo.

No se implementaron aplicación móvil, sensores, conciliación de caudalímetro, calidad, pagos ni ranking. Tampoco se modificaron los contratos existentes de login o productores.

## Permisos

El panel `/admin/acopios` exige sesión y el Gate `administrar-acopios`, que solo permite administradores activos. Cada arranque y acción Livewire vuelve a autorizar en el servidor. La API usa Sanctum y exige usuario activo con rol `recolector` para todas las operaciones de campo.

Un recolector solo recibe sus rutas activas y sus productores en orden de visita. No puede consultar una jornada de otro recolector, abrir una jornada de una ruta ajena ni registrar productores que no pertenezcan a la ruta. El servidor ignora cualquier relación manipulada desde el cliente y vuelve a verificarla dentro de la transacción.

## API de campo

Todas las rutas están bajo `auth:sanctum` y `EnsureActiveUser`.

`GET /api/v1/acopios/rutas` devuelve las rutas activas del recolector y cada productor con `id`, código, nombre y `orden`.

`POST /api/v1/acopios/jornadas` abre una jornada de su ruta asignada:

```json
{
  "ruta_id": 3,
  "fecha_operativa": "2026-09-09",
  "turno": "primera_vuelta",
  "observaciones": "Salida 06:30"
}
```

Responde `201` con `data.uuid_publico`, estado, responsable y entregas. La unicidad ruta/fecha/turno devuelve validación en español.

`GET /api/v1/acopios/jornadas/{uuid_publico}` consulta una jornada propia y sus entregas.

`POST /api/v1/acopios/sincronizar` recibe un lote de hasta 500 elementos. Cada uno contiene `uuid_cliente`, `jornada_id`, `productor_id`, `litros`, `recolectada_at` y `observacion` opcional:

```json
{
  "entregas": [
    {
      "uuid_cliente": "4e7d8c7e-70f3-4f5d-a3d7-7a775e4bd8d6",
      "jornada_id": 12,
      "productor_id": 91,
      "litros": "12.345",
      "recolectada_at": "2026-09-09 08:14:00",
      "observacion": ""
    }
  ]
}
```

La respuesta separa cada índice en `creados`, `repetidos` y `rechazados`. Los rechazados incluyen los errores por elemento; una entrega inválida no oculta ni cancela las demás. Los conflictos de productor, jornada cerrada, productor fuera de ruta, UUID inválido, litros no positivos y duplicados tienen mensajes explícitos.

## Panel administrativo

Abrir [http://localhost:8000/admin/acopios](http://localhost:8000/admin/acopios) con una cuenta administradora. El listado pagina 15 jornadas y filtra por fecha desde/hasta, ruta, recolector, estado y búsqueda de código o nombre. Cada fila muestra fecha, turno, ruta, recolector, productores atendidos y litros.

Desde **Nueva jornada** se crea la ejecución conservando el responsable actual. **Consultar** muestra entregas y permite cerrar o anular una jornada. La anulación solicita motivo. Mientras está abierta, cada entrega puede corregirse con litros, observación y motivo; después solo se consulta. El detalle conserva las referencias históricas aunque el productor o recolector haya cambiado posteriormente.

El dashboard incorpora jornadas abiertas y litros acopiados en el día, además de los indicadores existentes.

## Arquitectura y datos

`Domain/Acopios/AcopioRepository` define el contrato. `Application/Acopios/ConsultarAcopios` y `GestionarAcopios` contienen autorización, validación, transacciones, idempotencia y auditoría. `Infrastructure/Acopios` implementa Eloquent y consultas con eager loading, `withCount` y `withSum` para evitar consultas repetitivas. Los controladores usan Form Requests y Resources; la vista Livewire solo presenta y dispara acciones.

La migración `2026_09_09_010328_create_acopios_tables` crea `jornadas_acopio`, `entregas_acopio` y `auditorias_acopio`. Incluye UUID único público, UUID de cliente único, clave única de jornada/productor, unicidad ruta/fecha/turno, claves foráneas restrictivas e índices de fecha/estado. La migración pendiente se ejecutó una sola vez con `php artisan migrate --no-interaction`; no se usaron `migrate:fresh`, `migrate:refresh`, seeders ni eliminaciones.

Las escrituras usan `DB::transaction` y `lockForUpdate`; la creación de jornadas, la sincronización y correcciones no confían en IDs recibidos sin comprobar ruta, recolector, productor y estado. La prueba MySQL usa una base temporal exclusiva con motor InnoDB.

## Verificación

```sh
php artisan test
LACTOCOLUS_MYSQL_TESTS=1 php artisan test --compact tests/Feature/ConcurrentAcopiosMySqlTest.php
npm run build
php artisan route:list
vendor/bin/pint --dirty --format agent
git diff --check
```

Resultado de esta entrega: `php artisan test` pasó con 88 pruebas, 561 aserciones y 4 pruebas omitidas (las pruebas MySQL optativas y las existentes que requieren su entorno); la prueba MySQL exclusiva pasó con 1 prueba y 5 aserciones. `npm run build`, `route:list` y Pint finalizaron correctamente. El build conserva el aviso opcional existente sobre `fontaine`.

La prueba MySQL crea una base nueva `lactocolus_test_acopios_<hex>`, ejecuta allí las migraciones, lanza dos procesos que sincronizan el mismo UUID y elimina únicamente esa base al terminar. Si el servidor MySQL o el permiso de crear bases no está disponible, se omite y se debe informar la limitación sin apuntar nunca a la base local.

No había navegador conectado para una revisión visual interactiva. No se hicieron commits ni push.
