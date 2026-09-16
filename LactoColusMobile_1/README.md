# LactoColus móvil

App de campo para la **Planta Láctea Colus** (Puno, Perú). Kotlin Multiplatform + Compose
Multiplatform, **offline-first**: la base de datos local SQLite es la fuente de verdad y la
sincronización con el backend es diferida.

Esta entrega cubre interfaz completa, navegación por rol, base de datos SQLite real con
esquema y migraciones, repositorios y casos de uso, y la cola de sincronización con sus
estados. **No** incluye llamadas reales al backend (interfaces `data/remote` listas + datos
simulados en `data/mock`), autenticación real, sensores, Bluetooth, GPS ni mapas.

## Arquitectura

Clean Architecture en un solo módulo Gradle (`shared`), con las capas separadas por
**paquete** y las dependencias apuntando siempre hacia dentro
(`feature → domain ← data`, y `core` transversal). Se optó por un módulo único —en lugar de
~15 módulos Gradle— para minimizar el riesgo de configuración sobre el toolchain nuevo
(AGP 9 / Kotlin 2.4 / Gradle 9.1) y priorizar que **Android compile**.

```
shared/src/commonMain/kotlin/pe/lactocolus/mobile/
  core/
    common/        Result<T> sellado, AppError, DispatcherProvider, Reloj, UUID
    designsystem/   Color · Type · Dimens · Theme  +  components/ (24 componentes)
    navigation/     Destino (28 pantallas), Navegador (pila en memoria), barras por rol
    ui/             ConnectionState, SyncState, ContentState, formatos (litros/fecha/PEN)
  domain/
    model/          entidades y value objects (Kotlin puro)
    repository/      contratos (Flow para lectura; escritura local + encolado)
    usecase/         un caso de uso por operación, operator fun invoke(...)
  data/
    local/          DriverFactory (expect/actual), mappers fila→dominio
    remote/          DTOs @Serializable + interfaz LactoColusApi (no conectada)
    repository/      implementaciones: leen Flow de SQLite, escriben local + cola_sync
    sync/            SyncEngine (cola en orden, backoff exponencial, sincronizar todo,
                     reintento individual), EstadoApp, ConnectivityObserver (expect/actual)
    mock/            FakeLactoColusApi + SeedRepositoryImpl (solo debug/preview)
  di/               módulos Koin (core, data, domain, feature, platform)
  feature/           auth · recolector · calidad · productor · sync · perfil
                     (cada uno: ViewModel + UiState + eventos + pantallas Compose)
  App.kt            raíz: Koin + tema + Scaffold + tira de conexión + NavHostApp
```

`domain/` es Kotlin puro: sin Android, Compose, SQLDelight ni serialización.

## Sistema de diseño (`core/designsystem`)

Material 3 con esquema propio. Paleta clara tomada de la hoja de diseño aprobada; el esquema
oscuro se deriva conservando las relaciones de rol (identidad violeta, fondo cálido,
advertencia ámbar, error ladrillo) y elevando superficies.

- **Color**: `primary #5A2D82`, `success #1F6F5C`, `warning #96560C`, `error #A02417`,
  `background #F5EAD8`, `surface #FDF8EF`, `outline #DCD3C4`. `success`/`warning` viven en
  `ColoresSemanticos` (Material 3 no tiene esos slots), accesibles con `LactoTheme.semanticos`.
- **Tipografía**: escala `displayLarge 40 · displaySmall 30 · headlineMedium 26 · bodyLarge
  15 · labelMedium 13 · mono 12`. Los tipos de marca (Caprasimo / Figtree / IBM Plex Mono)
  **no están incluidos** en esta entrega —la descarga de assets está bloqueada en el entorno—
  y `DisplayFamily` / `BodyFamily` / `MonoFamily` en `Type.kt` caen a familias del sistema.
  Es el único punto a tocar para incorporar las TTF reales
  (`composeResources/font/` + `FontFamily(Font(Res.font.…))`).
- **Espaciado** 4·8·16·24·40 · **Formas** 8·16·22·pill · **Elevación** 3 pasos (tarjetas usan
  el más bajo) · **Área táctil** mín. 48 dp, acción principal 64 dp.
- **Componentes** (sin datos simulados dentro): `BarraSuperior`, `BarraInferior`,
  `TiraConexion`, `TarjetaIndicador`, `TarjetaProductor`, `TarjetaRuta`, `CampoTexto`,
  `CampoNumerico`, `SelectorSegmentado`, `SelectorLista`, `BotonPrincipal`/`BotonSecundario`,
  `EtiquetaEstado` (+ `EtiquetaSync`/`EtiquetaJornada`/`EtiquetaResultadoAnalisis`),
  `Alerta`, `DialogoConfirmacion`, `EstadoVacio`/`EstadoError`/`EstadoSinConexion`/
  `EstadoCargando`/`EsqueletoLista`, `PantallaLista`, `PantallaScroll`, `ChipCodigo`.

### Accesibilidad

Ningún estado se comunica solo con color: `EtiquetaEstado` y las alertas siempre llevan
color + icono + texto. `contentDescription` en botones de icono (volver, notificaciones).
Formularios con scroll y sin alturas fijas en cajas de texto.

## Roles y navegación

Tras el login la app dirige al flujo del rol. Navegador propio (`core/navigation/Navegador`):
pila en memoria con `navegar` / `atras` / `seleccionarPestana` / `reemplazarRaiz`. Se
prefirió a una librería de navegación para no añadir riesgo de resolución de dependencias;
es testeable en `commonTest` sin Android.

| Rol | Barra inferior | Pantallas |
|---|---|---|
| **Recolector** | Inicio · Ruta · Sincronización · Historial · Perfil | inicio con jornada activa, mis rutas, detalle de ruta, lista de productores con búsqueda, registrar entrega, confirmación, resumen de jornada, sincronización, historial |
| **Calidad** | Inicio · Nuevo análisis · Historial · Sincronización · Perfil | inicio (hoy/observados/pendientes/sin sincronizar), buscar productor, nuevo análisis por secciones, resultado, historial |
| **Productor** | Inicio · Entregas · Calidad · Más | inicio, mis entregas por semana, mi calidad en lenguaje sencillo, Muro de Honor (filtro diario/semanal/mensual y por ruta), comunicados (leído/no leído), solicitar traslado (resumen antes de enviar), mis liquidaciones con comprobante |

Comunes: login, notificaciones, perfil (tema claro/oscuro/sistema, versión, cerrar sesión).

Las 28 pantallas del prototipo (`LactoColus.html`, arreglo `INDICE`) están navegables. Las
pantallas clave están a fidelidad completa con componentes reales y datos simulados; las
secundarias (`r_hist`, `c_hist`, `p_mas`, `notif`) son versiones funcionales más ligeras.

## Esquema SQLite (SQLDelight)

`shared/src/commonMain/sqldelight/pe/lactocolus/mobile/db/*.sq` — genera `LactoColusDb`.

| Tabla | Contenido |
|---|---|
| `usuario` | sesión: id, nombre, correo, rol, token, vigencia |
| `ruta` | código, nombre, turno, vuelta, estado, total de productores |
| `productor` | código, nombre, ruta, orden de visita, activo, promedio de litros |
| `jornada` | ruta, turno, vuelta, fecha, estado (abierta/cerrada/anulada), totales, `estado_sync` |
| `entrega` | jornada, productor, litros, `no_entrego`, fecha/hora, observación, auditoría de corrección, `estado_sync` |
| `analisis` | productor, fecha, equipo, fuente, resultado, `agua_anadida`, `estado_sync` |
| `parametro_analisis` | análisis, clave, valor, unidad, `dentro_de_rango`, límites |
| `comunicado` | tipo, título, cuerpo, fecha, leído |
| `liquidacion` | semana, litros, precio, bonos, penalizaciones, descuentos, total, estado de pago |
| `ranking` | periodo, fecha, posición, productor (etiqueta pública), ruta, puntuación |
| `solicitud_traslado` | ruta origen/destino, fecha deseada, motivo, estado, anticipación, `estado_sync` |
| `cola_sync` | tipo de entidad, id de entidad, clave de idempotencia, payload JSON, intentos, último error, estado |

- Todo registro creado en el dispositivo nace con `id_local` (UUID) y `estado_sync =
  'PENDIENTE'`; el `id_remoto` se rellena al confirmarse. La **clave de idempotencia** por
  registro reutiliza su `id_local` (coincide con `uuid_cliente` / `uuid_externo` del backend)
  para que un reintento no duplique en la planta.
- Índices en `entrega(jornada, productor)`, `cola_sync(estado)`, `cola_sync(creado_en)`,
  `analisis(productor, fecha)`, `productor(ruta, orden)`, `jornada(estado)`,
  `ranking(periodo, fecha, posicion)`.
- Nada se borra automáticamente. Migraciones versionadas desde v1: el `.sq` es la versión 1
  y SQLDelight ejecuta cualquier archivo `.sqm` que se agregue junto al esquema.

### Estrategia de sincronización (`data/sync/SyncEngine`)

Procesa `cola_sync` en orden de creación, con reintento exponencial (1s·2s·4s… tope 30s)
entre elementos. Expone `Sincronizar todo` y reintento individual, con el detalle del error.
Al recuperar conexión, `RootViewModel` observa `ConnectivityObserver` y dispara la cola.

Estados por registro: `PENDIENTE` · `ENVIANDO` · `CREADO` · `REPETIDO` (ya existía en la
planta, no se duplica) · `RECHAZADO` (con mensaje del servidor). La respuesta simulada
divide el lote en `creados` / `repetidos` / `rechazados` igual que
`/api/v1/acopios/sincronizar`, y el motor actualiza tanto la fila de `cola_sync` como la
entidad subyacente.

### Estados globales (spec §6)

`EstadoApp` (singleton Koin) expone `ConnectionState` y `SyncState` como `StateFlow`.
`TiraConexion`, siempre visible bajo la barra superior, muestra estado de conexión, cantidad
de registros pendientes y fecha/hora de la última sincronización.

## Reglas de negocio implementadas

De `backend/docs/*.md` y `backend/routes/api.php`:

- **Litros** decimales, incrementos de 0,5 y atajos de valores frecuentes; se muestra el
  promedio del productor; una desviación ≥ 40 % frente al promedio **exige observación**
  (`RegistrarEntrega`).
- **No entregó**: bandera explícita, no litros = 0.
- **Corrección** de entrega solo mientras la jornada sigue abierta; guarda valor anterior,
  valor nuevo, motivo obligatorio y fecha (`CorregirEntrega` + tabla `entrega`).
- **Cierre de jornada** con confirmación, funciona sin conexión.
- **Análisis**: rangos de captura del formulario Lactoscan (`control-calidad.md`), no
  criterios de conformidad; sin perfil el resultado queda `pendiente_revision`. Si el equipo
  reporta **agua añadida > 0**, la muestra se marca `observado` y se avisa de forma
  destacada (`Alerta` crítica).
- **Ranking**: solo nombre, ruta y puntuación (privacidad).
- **Traslados**: la fecha efectiva debe ser al menos hoy + plazo de anticipación. El plazo
  es configurable (1–365 días, `solicitudes-traslado.md`); esta entrega usa **10 días**
  (`TrasladoRepositoryImpl.anticipacionDias`) — ajústalo al valor aprobado en el SRS.
- **Liquidaciones**: solo del productor autenticado.

## Datos simulados (`data/mock`)

`SeedRepositoryImpl` siembra la base local en el primer login (idempotente, solo si las
tablas están vacías): una ruta activa con productores en orden de visita, una jornada
abierta con entregas pendientes y sincronizadas y un «no entregó», una jornada cerrada de
ayer, un análisis conforme y uno observado con agua añadida, ranking (diario/semanal/
mensual), comunicados leídos y no leídos, una solicitud de traslado y dos liquidaciones
semanales. La `cola_sync` queda coherente con los registros pendientes.

`FakeLactoColusApi` simula el backend: divide cada lote en creados/repetidos/rechazados y
"recuerda" UUID para devolver `repetido` en un reenvío.

## Cuentas de demostración

Login con cualquier contraseña de 4+ caracteres. El rol se deriva del correo:

| Correo | Rol |
|---|---|
| `recolector@lactocolus.test` | Recolector |
| `calidad@lactocolus.test` | Personal de calidad |
| `productor@lactocolus.test` | Productor |

## Ejecutar Android

Requiere un JDK 21 (el toolchain de Gradle lo aprovisiona). Si `./gradlew` está bloqueado
por la cuarentena de macOS, invócalo como `sh ./gradlew …`.

```sh
./gradlew :androidApp:assembleDebug        # APK debug
./gradlew :androidApp:installDebug          # instalar en dispositivo/emulador
```

## Ejecutar pruebas

```sh
./gradlew :shared:testAndroid              # pruebas commonTest + androidHostTest
```

Cobertura (spec §10):

- `RegistrarEntregaTest` — happy path, «no entregó» ≠ 0, desviación exige observación,
  jornada cerrada, entrega duplicada.
- `EstadoSyncMapeoTest` — mapeo de estados incluidos `REPETIDO` y `RECHAZADO`.
- `NavegacionRolTest` — inicio y barra por rol, pila del navegador.
- `DaoEnMemoriaTest` (androidHostTest) — DAOs de SQLDelight contra base en memoria
  (`entrega`, `cola_sync` en orden y por estado, `analisis` con parámetros y conteos).

## Previews

`@Preview` en tema claro y oscuro para: login, indicadores del recolector, alerta de agua
añadida (calidad), fila de ranking, comprobante de liquidación, fila de cola de
sincronización (pendiente y rechazada). Están en los archivos `*Screen(s).kt` de cada
feature. Se abren desde el panel de Preview de Android Studio / IntelliJ; en este entorno no
hay captura headless de previews.

## iOS

El código está estructurado para iOS (`iosMain` con `DriverFactory` nativo,
`MainViewController`, `ConnectivityObserver` y módulo Koin de plataforma).

Estado verificado en este entorno:

- **El código Kotlin compila** para `iosSimulatorArm64` y `iosArm64`
  (`./gradlew :shared:compileKotlinIosSimulatorArm64 :shared:compileKotlinIosArm64` → OK).
- **El enlace del framework falla**:
  `./gradlew :shared:linkDebugFrameworkIosSimulatorArm64` → *"An error occurred during an
  xcrun execution. Make sure that Xcode and its command line tools are properly installed."*
  El host es macOS **x86_64** y Kotlin/Native marca `macos_x64` como host deprecado; además
  las herramientas de línea de comandos de Xcode no están completas aquí.
- Las pruebas de iOS no pueden ejecutarse: `iosSimulatorArm64Test` requiere un host ARM64.

En un Mac Apple Silicon con Xcode instalado el framework debería enlazar; queda pendiente de
verificación en ese entorno.

## Estado del backend

`git status -- backend/` no debe mostrar cambios: esta entrega **no toca `backend`**.

## Pasos futuros para conectar la API

1. Implementar `LactoColusApi` con Ktor (`data/remote/KtorLactoColusApi`), apuntando a
   `/api/v1/...`; sustituir `single<LactoColusApi> { FakeLactoColusApi() }` en `coreModule`.
2. Autenticación real en `AuthRepositoryImpl` (token Sanctum, `POST /api/v1/login`,
   `Authorization: Bearer`), manejo de `SesionVencida` → `EstadoApp.marcarSesionVencida()`.
3. `SyncEngine`: usar los endpoints de lote reales (`/acopios/sincronizar`,
   `/calidad/sincronizar`, hasta 500 / 100 elementos por lote) y mapear los errores por
   índice a `RECHAZADO`.
4. Descargar catálogos (`/acopios/rutas`, `/calidad/productores`, `/ranking`,
   `/comunicados`, `/traslados/opciones`, liquidaciones) hacia las tablas locales.
5. Incorporar los tipos de marca y, si se requiere, una librería de navegación tipada.
6. Verificar y habilitar el target de iOS en un Mac ARM64.
