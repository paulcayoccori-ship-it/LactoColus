# Sexta entrega: control de calidad en campo

## Base documental y alcance

Se revisaron AGENTS.md, las reglas locales, Graphify, `docs/acopios.md`, `docs/rutas-acopio.md` y `docs/recepcion-conciliacion.md`. No se encontró un SRS independiente ni una fórmula aprobada de corrección de densidad en el repositorio. La referencia al SRS de la entrega se toma de los requisitos proporcionados por el usuario; no se usaron adjuntos de ChatGPT.

Se registra una muestra Lactoscan por productor, con vínculos opcionales a entrega/jornada. No hay hardware, aplicación móvil, descuentos, sanciones, pagos, ranking, inventario ni cambios en los contratos de API anteriores. No se cargan muestras ni usuarios de demostración.

## Campos, unidades y captura

Todos los resultados se almacenan como `DECIMAL(10,4)` y se devuelven como cadenas con cuatro decimales. Los criterios y sus copias históricas también normalizan sus valores a cadenas decimales. La evaluación usa BCMath, sin convertir las mediciones persistidas a float.

| Campo | Unidad | Validación de captura |
| --- | --- | --- |
| grasa | % masa/masa | 0–100 |
| proteina | % masa/masa | 0–100 |
| lactosa | % masa/masa | 0–100 |
| densidad_medida | g/mL | Mayor que 0, hasta 2 |
| temperatura | °C | 0–100 |
| densidad_corregida | g/mL | Opcional; mayor que 0, hasta 2 |
| solidos_no_grasos | % masa/masa | 0–100 |
| ph | unidades de pH | 0–14 |
| acidez | % de ácido láctico (acidez titulable) | 0–100 |
| agua_anadida | % | 0–100 |

Estos son controles amplios de captura para detectar negativos, errores de unidad y valores impropios para este formulario de leche líquida. Los máximos de densidad y temperatura son decisiones provisionales de entrada que deben contrastarse con la ficha del modelo concreto de Lactoscan; no son constantes científicas de corrección, rangos regulatorios ni criterios sanitarios de aceptación. No se infiere conformidad por pasar esta validación. No se convierte automáticamente de kg/m³, grados Dornic u otras unidades: los datos deben entregarse en las unidades de la tabla. Se rechazan más de cuatro decimales y fechas futuras.

Otros campos:

- `uuid_publico`: generado por el servidor, identifica el análisis al consultar.
- `uuid_externo`: obligatorio en API y generado una sola vez al abrir el formulario web; índice único para sincronización.
- `productor_id`: obligatorio, activo y no eliminado al registrar.
- `entrega_id` y `jornada_id`: opcionales. Una entrega debe pertenecer al productor; si se envían ambos deben coincidir. La jornada no puede estar anulada. Si solo se indica una jornada, se comprueba la asignación actual del productor a su ruta.
- `ruta_id`: derivada de la entrega, jornada o asignación del productor al momento del registro, nunca elegida libremente por el cliente. Para sincronización tardía que deba conservar otra ruta, debe vincularse la entrega histórica correspondiente.
- `responsable_id`: usuario autenticado que registra; no editable por el cliente.
- `muestra_at`, `sincronizada_at`: hora de muestra y recepción por el servidor. Se normalizan a `config('app.timezone')`; los ejemplos con offset se convierten a esa zona.
- `equipo`: identificación opcional, hasta 100 caracteres.
- `fuente`: `manual` o `dispositivo` (importación/simulación, sin conexión física).
- `observaciones`: opcional, hasta 5000 caracteres.

Se permiten varias muestras por productor o entrega, cada una con distinto UUID externo. No hay eliminación física. Las claves foráneas restringen borrados; las consultas históricas incluyen productores eliminados lógicamente y personas/rutas inactivas.

## Densidad corregida

No se implementó una fórmula ni se asumieron constantes o temperatura de referencia. La densidad medida y la corregida son campos separados. Si el operador no dispone de una densidad corregida validada, puede dejarla vacía; ello deja el análisis pendiente.

`Domain/Calidad/CorrectorDensidad` define `corregir(string $densidad, string $temperatura): ?string`. La implementación actual `DensidadSinFormula` devuelve null y está conectada al caso de uso. Una futura implementación deberá incluir fórmula y versión aprobadas, pruebas técnicas y definición de unidades/temperatura de referencia. Nunca se presenta la densidad medida como corregida automáticamente.

## Perfiles versionados y evaluación

La pantalla de perfiles permite guardar nueve criterios: todos los parámetros salvo densidad medida. Cada criterio tiene mínimo, máximo, unidad, activo, fecha inicial y fecha final opcional. La unidad es explícita y se valida contra la unidad del parámetro; no se permiten conversiones implícitas. El máximo no puede ser menor al mínimo. Se pueden guardar perfiles incompletos para su preparación.

Cada guardado genera una versión global nueva e inmutable, con nombre, autor, fecha, estado activo y vigencia del perfil. “Consultar / usar como base” copia una versión al formulario; guardarla crea otra versión. Para suspender un perfil se guarda una versión inactiva con la vigencia correspondiente.

Selección determinista: de las versiones cuya vigencia cubra la fecha de muestra se toma la de mayor número. Una versión inactiva aplicable suspende la evaluación y no hace reaparecer silenciosamente un perfil anterior. No hay límites predeterminados ni actualización masiva de análisis previos.

- `pendiente_revision`: no hay versión activa aplicable, falta algún criterio activo/vigente/completo o falta densidad corregida.
- `conforme`: todos los criterios y mediciones están completos y dentro de sus límites, inclusive mínimo y máximo.
- `observado`: criterios completos y al menos un parámetro fuera de rango.
- `anulado`: anulación administrativa con motivo; excluido de indicadores.

El resultado guarda el perfil completo utilizado, su versión y las advertencias por parámetro (valor, unidad y límites cuando está fuera de rango). Si el perfil es incompleto se conserva lo disponible y se explica qué falta; no se declara conforme. La evaluación solo clasifica resultados para revisión técnica, sin consecuencias económicas.

## Corrección, revisión y auditoría

Solo administradores activos pueden:

- Corregir mediciones, fecha, equipo, fuente u observaciones, con motivo. Se mantienen productor, referencias históricas, UUID y responsable original. Se recalcula con la copia de criterios original; si la nueva fecha queda fuera de esa vigencia, pasa a pendiente.
- Revisar explícitamente con la versión actualmente disponible que aplique a la fecha de muestra. Exige motivo, conserva la evaluación anterior en auditoría y puede cambiar el perfil aplicado. No permite forzar un estado conforme sin criterios completos.
- Anular con motivo obligatorio. El análisis y las entregas originales permanecen intactos; un análisis anulado no admite más modificaciones.

Cada operación guarda valores anteriores/nuevos completos, acción, motivo, usuario y fecha en `auditorias_calidad`. La auditoría se consulta en el detalle administrativo. No se reemplazan resultados silenciosamente. La repetición de un UUID devuelve el original con estado de sincronización `repetido`, incluso si el reintento contiene valores distintos; para corregir se usa la acción administrativa auditada.

## Permisos

La migración añade el rol Spatie `calidad` con guard `web`, sin asignárselo a nadie ni crear usuarios. El administrador puede asignarlo desde Usuarios mediante el flujo existente.

| Operación | Administrador activo | Calidad activo | Otros roles |
| --- | --- | --- | --- |
| Consultar productores activos para control | Sí | Sí | No |
| Registrar / sincronizar análisis | Sí | Sí | No |
| Listar / consultar resultados | Todos | Solo propios | No |
| Perfiles, correcciones, revisiones, anulaciones | Sí | No | No |

Gates: `operar-calidad` y `administrar-calidad`. Se comprueban en rutas, Form Requests, casos de uso y cada acción Livewire; se consulta el estado/rol actual, incluida revocación en una sesión existente. El personal de calidad entra a su panel después del login y no obtiene acceso al dashboard administrativo, usuarios, rutas ni configuraciones. Los permisos anteriores de otros roles se conservan.

## API v1 con Sanctum

Enviar `Authorization: Bearer <token>` y `Accept: application/json`. No cambian login ni endpoints anteriores.

| Método | Endpoint | Resultado |
| --- | --- | --- |
| GET | `/api/v1/calidad/productores?buscar=PRO&page=1` | Productores activos, 30 por página, sin datos de contacto |
| POST | `/api/v1/calidad/analisis` | Crea o devuelve un análisis por UUID externo |
| POST | `/api/v1/calidad/sincronizar` | Lote de 1 a 100 análisis |
| GET | `/api/v1/calidad/analisis/{uuid_publico}` | Resultado propio, o cualquiera si es administrador |

Ejemplo de registro (valores ilustrativos, no perfil técnico):

```json
{
  "uuid_externo": "1a32c7bd-43dc-4c59-9417-45cd660ec901",
  "productor_id": 12,
  "muestra_at": "2026-09-09T06:30:00-05:00",
  "equipo": "Lactoscan-portatil-01",
  "fuente": "manual",
  "grasa": "3.5000",
  "proteina": "3.2000",
  "lactosa": "4.5000",
  "densidad_medida": "1.0300",
  "temperatura": "20.0000",
  "densidad_corregida": null,
  "solidos_no_grasos": "8.5000",
  "ph": "6.7000",
  "acidez": "0.1500",
  "agua_anadida": "0.0000",
  "observaciones": "Muestra tomada en la finca"
}
```

`201` al crear, `200` al repetir. La respuesta contiene `estado_sincronizacion` (`creado` o `repetido`) y `data` con UUID público/externo, relaciones, responsable, mediciones, fechas, estado, copia de límites y advertencias. Los campos controlados por el servidor no se aceptan como decisiones del cliente.

Ejemplo de lote (usar objetos completos como el anterior para registros nuevos):

```json
{
  "analisis": [
    {"uuid_externo": "1a32c7bd-43dc-4c59-9417-45cd660ec901"},
    {"uuid_externo": "incorrecto"}
  ]
}
```

Si el primer UUID ya existe para ese usuario, se devuelve en `data.repetidos`; el segundo queda en `data.rechazados`. Cada entrada de `creados`/`repetidos` identifica índice, UUID público/externo y estado; cada rechazo identifica índice y errores. Una entrada inválida no cancela las válidas. La evaluación técnica pendiente u observada cuenta como registro creado, no como error de sincronización.

Errores: `401` sin token o usuario inactivo según middleware existente; `403` por rol insuficiente o repetición de UUID ajeno; `404` en consulta de análisis inexistente/ajeno; `422` por formato, valores o relaciones inválidas. Un lote estructuralmente válido responde `200` con resultado individual de todos sus elementos.

## Panel e indicadores

- `http://localhost:8000/admin/calidad`: lista paginada de 15 análisis; filtros por fechas, productor, ruta histórica, responsable, estado y agua añadida. Muestra parámetros principales, advertencias, detalle, límites y auditoría.
- `http://localhost:8000/admin/calidad/perfiles`: versiones, preparación de rangos y vigencias.
- Dashboard: análisis por fecha de muestra del día, total observado y total pendiente. Las tres métricas excluyen anulados.

Los selectores históricos permiten filtrar productores que posteriormente fueron desactivados/eliminados; el selector para crear solo ofrece activos. Las relaciones de tablas y auditorías se cargan anticipadamente para evitar N+1. Los botones usan el estado de petición Livewire/MaryUI, sin `$refs` ni un booleano persistente que bloquee Guardar. Se muestran errores en español y se permite reintentar.

## Arquitectura, migración y concurrencia

- `Domain/Calidad`: parámetros/unidades, contrato de consultas y corrector de densidad.
- `Application/Calidad`: consultas autorizadas, registro, evaluación, perfiles, sincronización y auditoría.
- `Infrastructure/Calidad`: modelos, repositorio Eloquent y corrector sin fórmula.
- API con Form Requests y Resource; Livewire solo coordina presentación y acciones.

La migración `2026_09_09_124241_create_control_calidad_tables.php` crea `perfiles_calidad`, `analisis_calidad`, `auditorias_calidad` y el rol `calidad`. Se ejecutó únicamente la migración pendiente en MySQL local, sin fresh/refresh, seeders, cambios de contraseña ni eliminación de registros existentes.

UUID externo y público tienen índices únicos; las versiones también. Las escrituras comparten `UsuarioRepository::underAdminLock` con usuarios/rutas para respetar el protocolo existente, revalidan autorización bajo bloqueo y bloquean productor/análisis al escribir. Esto serializa escrituras y evita carreras con cambios de roles/asignaciones. Es una elección conservadora de esta entrega: limita el paralelismo global de escrituras; podrá optimizarse en conjunto con ese protocolo después de medir carga.

## Pruebas y verificación

`CalidadTest` cubre registro, precisión, límites de captura, UUID, valores manipulados, roles, sesiones revocadas, relaciones productor/entrega/jornada, filtros, paginación, perfiles completos/incompletos/inactivos/vencidos, copias históricas, revisión, correcciones/anulaciones auditadas, lotes, recuperación del formulario y dashboard. La prueba anterior de productores se actualizó para contar el nuevo rol, manteniendo el contrato del seeder de desarrollo dentro de la base de pruebas.

```sh
vendor/bin/pint --dirty --format agent
php artisan test
LACTOCOLUS_MYSQL_TESTS=1 php artisan test --compact tests/Feature/ConcurrentCalidadMySqlTest.php
npm run build
php artisan route:list
git diff --check
```

Resultado final: `php artisan test` pasó con 138 pruebas (132 correctas, 6 omitidas), 746 aserciones. Las 35 pruebas funcionales de calidad pasaron con 161 aserciones, incluida la revisión de muestras cerca de medianoche en America/Lima. Pint, build, `route:list` (47 rutas) y `git diff --check` finalizaron correctamente. La migración local figura en el lote 9.

La prueba concurrente pasó en MySQL: crea una base aleatoria exclusiva `lactocolus_test_calidad_<hex>`, migra allí, arranca dos procesos con barrera de inicio y comprueba un creado, un repetido y una única fila con el mismo UUID público. Al terminar elimina solamente esa base temporal. El primer intento dentro del sandbox fue bloqueado; el reintento autorizado pasó con 1 prueba y 9 aserciones. No se usa SQLite como evidencia del bloqueo de MySQL.

Las pruebas funcionales se ejecutan en SQLite en memoria, forzado en phpunit.xml. No apuntan a la base local. El build mantiene el aviso opcional existente de `fontaine`. No se ha realizado validación física con Lactoscan ni validación científica de perfiles/fórmula. La verificación de interfaz cubre renderizado y acciones Livewire mediante pruebas; no incluye una sesión visual autenticada en navegador.

## Pasos de uso

1. Entrar como administrador y asignar `calidad` a un usuario existente mediante Usuarios, si corresponde.
2. Abrir Control de calidad y registrar una muestra de productor activo. Sin perfil completo, debe quedar pendiente; dejar la densidad corregida vacía si no está validada.
3. En Perfiles y rangos, introducir límites técnicamente aprobados y sus vigencias. Activar perfil y todos los criterios. Guardar crea una nueva versión.
4. Registrar una muestra dentro y otra fuera de los rangos configurados; consultar las advertencias concretas.
5. Revisar explícitamente un análisis pendiente con motivo para aplicar el perfil vigente a su fecha de muestra.
6. Corregir una medición con motivo y comprobar valor anterior/nuevo en Auditoría. Anular otra muestra y comprobar su exclusión del dashboard.
7. Iniciar sesión como personal de calidad: comprobar productores activos, registro y solo resultados propios, sin acceso a perfiles ni correcciones.
8. Enviar el mismo UUID externo dos veces por API; comprobar una creación y una repetición. Probar un lote con entradas válidas e inválidas.

Se ejecutó `graphify update .` al finalizar para actualizar el grafo de código. No se hicieron commits ni push.

## Extensión de fase 5: sólidos totales y ranking
Se añade `solidos_totales` opcional (decimal de cuatro posiciones, % m/m, 0–100 como dominio de captura). Es un dato medido separado; no se deduce de otros componentes. No altera la completitud de los perfiles de conformidad previos. Alta/corrección/revisión/anulación invalidan los snapshots de ranking de los periodos afectados, conservando el historial. Ver `ranking-calidad.md`.
