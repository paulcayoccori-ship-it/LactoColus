# Prompt de implementación — LactoColus móvil

> Copia todo el contenido de este archivo como prompt para el agente de código.
> Está escrito a partir del prototipo visual ya aprobado (`LactoColus.dc.html`,
> 28 pantallas, 3 roles) y de su hoja de sistema de diseño (`Sistema de diseño.dc.html`).

---

## 1. Encargo

Implementa la aplicación móvil **LactoColus** para la Planta Láctea Colus (Puno, Perú) en
**Kotlin Multiplatform** con **Compose Multiplatform**, persistencia local en **SQLite** y
**Clean Architecture**. La app funciona en el campo con conectividad intermitente: la base de
datos local es la fuente de verdad y la sincronización con el backend es diferida.

Antes de escribir código: lee `AGENTS.md`, el SRS del sistema, la documentación en
`backend/docs` y los endpoints existentes; revisa la estructura actual de `mobile` y respeta
sus convenciones y versiones. No modifiques `backend`. No hagas commit ni push.

Prioriza que **Android** compile y ejecute. Si iOS no puede compilarse por limitaciones de
macOS o Xcode, documéntalo sin bloquear Android; no afirmes que iOS compila si no ejecutaste
esa compilación.

---

## 2. Arquitectura

Clean Architecture en tres capas, con dependencias apuntando siempre hacia dentro
(`presentation → domain ← data`). El módulo `domain` es Kotlin puro: sin Android, sin
Compose, sin SQLDelight, sin Ktor.

```
mobile/
  composeApp/                     # entry points Android e iOS, DI raíz, navegación
  core/
    designsystem/                 # tokens, tema, componentes reutilizables
    ui/                           # estados de UI compartidos, formateadores de presentación
    navigation/                   # rutas, grafos por rol
    common/                       # Result, DispatcherProvider, utilidades
  domain/
    model/                        # entidades y value objects
    repository/                   # interfaces de repositorio
    usecase/                      # casos de uso, uno por operación
  data/
    local/                        # SQLDelight: esquema, queries, DAOs, mappers
    remote/                       # Ktor: DTOs, api services, mappers
    repository/                   # implementaciones de los repositorios de domain
    sync/                         # cola de sincronización y motor de reintentos
    mock/                         # proveedores de datos simulados (solo debug/preview)
  feature/
    auth/ recolector/ calidad/ productor/ sync/ perfil/
```

Reglas:

- Cada `feature/` contiene su ViewModel, su `UiState`, sus eventos y sus pantallas Compose.
- Un caso de uso por operación, con `operator fun invoke(...)`, que devuelve
  `Result<T>` o `Flow<T>`. Nada de lógica de negocio en ViewModels ni en composables.
- Los repositorios exponen `Flow` desde SQLite; la red solo escribe en la base local.
- Inyección de dependencias con Koin (o el contenedor que ya use el proyecto).
- Corrutinas y `Flow` para todo lo asíncrono; `DispatcherProvider` inyectable para tests.
- Un solo `Result<T>` sellado en `core/common`, sin excepciones cruzando capas.

---

## 3. Persistencia SQLite

Usa **SQLDelight** (driver Android + nativo iOS). El esquema local es la fuente de verdad y
la app debe ser completamente funcional sin red.

Tablas mínimas:

| Tabla | Contenido |
|---|---|
| `usuario` | id, nombre, correo, rol, token, vigencia de sesión |
| `ruta` | código, nombre, turno, vuelta, estado, total de productores |
| `productor` | código, nombre, ruta, orden de visita, estado activo |
| `jornada` | id, ruta, turno, vuelta, fecha, estado (abierta/cerrada), totales |
| `entrega` | id local, jornada, productor, litros, fecha y hora, observación, no_entregó, estado de sincronización |
| `analisis` | id local, productor, fecha, parámetros, resultado, estado de sincronización |
| `parametro_analisis` | análisis, clave, valor, unidad, dentro_de_rango |
| `comunicado` | id, tipo, título, cuerpo, fecha, leído |
| `liquidacion` | semana, litros, precio, bonos, penalizaciones, descuentos, total, estado de pago |
| `ranking` | periodo, posición, productor, puntuación, ruta |
| `solicitud_traslado` | id, ruta origen, ruta destino, fecha deseada, motivo, estado |
| `cola_sync` | id local, tipo de entidad, id de entidad, payload, intentos, último error, estado |

Requisitos:

- Todo registro creado en el dispositivo nace con `id_local` (UUID) y
  `estado_sync = PENDIENTE`; el `id` remoto se rellena al confirmarse.
- Clave de idempotencia por registro para que un reintento no duplique en la planta.
- Migraciones versionadas desde el primer día.
- Índices en `entrega(jornada, productor)`, `cola_sync(estado)` y `analisis(productor, fecha)`.
- Nada se borra automáticamente: un registro pendiente sobrevive al cierre de la app.

Estados de sincronización por registro: `PENDIENTE`, `ENVIANDO`, `CREADO`, `REPETIDO`
(ya existía en la planta, no se duplica), `RECHAZADO` (con mensaje del servidor).

Motor de sincronización en `data/sync`: procesa `cola_sync` en orden, con reintento
exponencial, `Sincronizar todo`, reintento individual y detalle del error. Al recuperar
conexión se dispara solo.

---

## 4. Roles y navegación

Después del inicio de sesión la app dirige automáticamente al flujo del rol.

**Recolector** — barra inferior: Inicio · Ruta · Sincronización · Historial · Perfil
Pantallas: inicio con jornada activa; mis rutas; detalle de ruta con progreso; lista de
productores con búsqueda por nombre o código; registrar entrega; confirmación; resumen de
jornada; sincronización; historial de jornadas.

**Personal de calidad** — Inicio · Nuevo análisis · Historial · Sincronización · Perfil
Pantallas: inicio con análisis de hoy, observados, pendientes y sin sincronizar; buscar
productor; nuevo análisis por secciones (composición, propiedades físicas, acidez y
adulteración, datos de muestra); resultado; historial con filtros.

**Productor** — Inicio · Entregas · Calidad · Más
Dentro de Más: Ranking · Comunicados · Traslados · Liquidaciones · Perfil
Pantallas: inicio; mis entregas por semana; mi calidad en lenguaje sencillo; Muro de Honor
con filtro diario/semanal/mensual y por ruta; comunicados con leído/no leído; solicitar
traslado con resumen antes de enviar; mis liquidaciones con comprobante.

Comunes: splash, inicio de sesión, notificaciones, perfil con tema claro/oscuro/sistema,
cerrar sesión e información de versión.

---

## 5. Reglas de negocio a respetar

- **Litros**: campo grande, teclado numérico decimal, incrementos de 0,5 L y atajos con
  valores frecuentes. Se muestra el promedio del productor como referencia; un valor muy
  distinto exige observación.
- **No entregó**: alternativa explícita al registro de litros, no un valor 0.
- **Corrección**: solo mientras la jornada siga abierta. Cada corrección guarda valor
  anterior, valor nuevo, motivo obligatorio, autor, fecha y hora.
- **Cierre de jornada**: pide confirmación, se puede cerrar sin conexión y después ya no se
  corrige sin autorización del jefe de producción.
- **Análisis**: no inventes rangos técnicos; toma los umbrales del SRS o del backend. Si el
  equipo reporta agua añadida, la muestra se marca observada y se avisa de forma destacada.
- **Privacidad del ranking**: solo nombre, ruta y puntuación. Nunca DNI, teléfono, dirección
  ni información financiera de terceros.
- **Liquidaciones**: solo del productor autenticado.
- **Traslados**: se piden con al menos 10 días de anticipación (confirma el valor real en el
  SRS antes de fijarlo en código).

---

## 6. Estados globales

Modela un `ConnectionState` y un `SyncState` observables por toda la app:

`ConConexion` · `SinConexion` · `Sincronizando` · `SincronizacionCorrecta` ·
`DatosPendientes` · `ErrorSincronizacion` · `SesionVencida` · `Cargando` · `SinResultados` ·
`ErrorServidor`

Siempre visible, en una tira bajo la barra superior: estado de conexión, cantidad de
registros pendientes y fecha y hora de la última sincronización.

---

## 7. Sistema de diseño

Centraliza todo en `core/designsystem`. Material 3 con esquema propio.

**Color** (claro; deriva el esquema oscuro conservando las relaciones de contraste):

| Rol | Hex | Uso |
|---|---|---|
| primary | `#5A2D82` | barra superior, acción principal, selección |
| primary pressed | `#3D1C5C` | estado presionado |
| primaryContainer | `#EEE4F6` | pestaña activa, botón secundario |
| onPrimary | `#FDF8EF` | texto sobre violeta |
| success | `#1F6F5C` | atendido, sincronizado, conforme |
| successContainer | `#D7EFE6` | fondo de etiqueta conforme |
| warning | `#96560C` | sin conexión, pendientes, observado |
| warningContainer | `#FFE7CC` | tira de sin conexión |
| error | `#A02417` | rechazado, agua añadida, error de servidor |
| errorContainer | `#FBDED9` | fondo de alerta crítica |
| background | `#F5EAD8` | fondo de pantalla |
| surface | `#FDF8EF` | tarjetas, barra inferior, campos |
| onBackground | `#201E1D` | texto principal |
| outline | `#DCD3C4` | bordes y separadores |

**Tipografía**: Caprasimo solo para títulos y cifras grandes; Figtree para el resto; IBM Plex
Mono para códigos. `displayLarge` 40 · `headlineMedium` 26 · `displaySmall` 30 (indicadores)
· `bodyLarge` 15 · `labelMedium` 13 · mono 12. El cuerpo nunca baja de 13 sp.

**Espaciado**: 4 · 8 · 16 · 24 · 40 dp.
**Formas**: 8 · 16 · 22 dp y pill (999). Nada de esquinas rectas.
**Elevación**: tres pasos suaves; las tarjetas usan el más bajo.
**Área táctil**: mínimo 48 dp; la acción principal de cada pantalla, 64 dp de alto.

**Componentes reutilizables** (sin datos simulados dentro): barra superior, navegación
inferior, tira de estado de conexión, tarjeta de indicador, tarjeta de productor, tarjeta de
ruta, campo de texto, campo numérico con unidad, selector, selector segmentado, botón
principal y secundario, etiqueta de estado, alerta, diálogo de confirmación, estado vacío,
estado de carga con esqueleto, estado de error, estado sin conexión, indicador de
sincronización, lista de pendientes, snackbar, tarjeta de calidad, tarjeta de liquidación y
fila de parámetro técnico.

---

## 8. Accesibilidad

- Ningún estado se comunica solo con color: siempre color, icono y texto juntos.
- Contraste alto sobre fondo crema; texto de párrafo por encima de 4,5:1.
- `contentDescription` en todo botón de icono, incluidos volver y notificaciones.
- Formularios desplazables, sin alturas fijas en cajas de texto, para soportar aumento del
  tamaño de letra.
- Acciones principales siempre visibles; nada de texto cortado.

---

## 9. Alcance de esta entrega

Sí: interfaz completa, navegación por rol, base de datos SQLite real con su esquema y
migraciones, repositorios y casos de uso, cola de sincronización con sus estados.

No todavía: llamadas reales al backend (deja las interfaces `remote` listas y devuelve datos
simulados desde `data/mock`), autenticación real, sensores, Bluetooth, GPS y mapas.

Los datos simulados viven en `data/mock`, separados de la UI, y cubren: una ruta activa,
varios productores, una jornada abierta, entregas pendientes y sincronizadas, un análisis
conforme, uno observado con agua añadida, ranking, comunicados, una solicitud de traslado y
una liquidación semanal.

---

## 10. Previews y pruebas

`@Preview` para: login; inicio del recolector; ruta; registro de entrega; sincronización;
inicio de calidad; formulario de análisis; resultado observado; inicio del productor;
entregas; ranking; liquidación; y los estados vacío, error y sin conexión. Cada una en tema
claro y oscuro.

Pruebas: los casos de uso de registro de entrega y de encolado en `cola_sync`; los DAOs de
SQLDelight contra base en memoria; navegación por rol; y el mapeo de estados de
sincronización, incluidos `REPETIDO` y `RECHAZADO`.

---

## 11. Verificación y documentación

Compila Android, ejecuta las pruebas existentes y aplica el formateador. Verifica que
`backend` no cambió. Genera capturas o previews de las pantallas principales si el entorno lo
permite; si no puedes hacer revisión visual, dilo claramente.

Crea o actualiza `mobile/README.md` con: arquitectura visual, sistema de diseño, roles,
navegación, pantallas implementadas, esquema de SQLite y estrategia de sincronización, datos
simulados, cómo ejecutar Android, cómo abrir previews, limitaciones actuales y pasos futuros
para conectar la API.

Al terminar informa: archivos creados y modificados; pantallas implementadas; navegación por
rol; esquema de base de datos creado; resultado de compilación; resultado de pruebas;
limitaciones de Android o iOS; ruta de las previews o capturas; y el resultado de
`git status`. No hagas commit ni push.
