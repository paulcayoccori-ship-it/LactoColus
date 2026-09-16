# Inventario y ventas de queso

## Alcance y decisiones
Fase 2: stock por tipo Paria fresco/pasteurizado, movimientos, clientes, ventas y tarifas. Solo administradores activos, tanto en panel como API. No modifica contratos anteriores ni mobile. No carga clientes, ventas o producción de demostración.

Se utilizan los importes indicados en la solicitud del proyecto: mayorista S/ 20.00, proveedor S/ 18.00, público general S/ 21.00 por molde, para ambos tipos de queso. La configuración inicial es versión 0; cambiar tarifas crea versiones inmutables en reglas_operativas. Cada venta conserva cliente, categoría, precios y versión de reglas. El SRS completo no fue localizado en el repositorio: estas cifras provienen de los requisitos explícitos del usuario.

El límite ambiguo de 15–20 moldes no se fija automáticamente. Un administrador debe definir cantidad y alcance: por venta o semana jueves–miércoles. Sin cantidad configurada, se rechazan nuevas ventas a proveedores. Se suman ventas confirmadas/pagadas de la semana según la fecha operativa de venta y la regla copiada en cada venta. Los borradores no reservan stock ni cupo. Para corregir una venta se anula con motivo y se crea otra: no hay sobrescritura silenciosa de detalles.

## Operaciones e históricos
- Borrador → confirmada → pagada; anulación desde cualquier estado con motivo. No eliminación física.
- Confirmar descuenta existencias de todos los detalles dentro de una transacción; si falta un tipo, se revierte toda la operación.
- Anular una venta confirmada/pagada genera movimientos inversos una sola vez. La reversión del inventario no efectúa un reembolso bancario.
- Finalizar producción ingresa moldes automáticamente. Los ajustes de producción modifican stock con su UUID, motivo y auditoría. Anular un lote finalizado retira moldes; se rechaza si deja stock negativo. Los litros consumidos no se liberan.
- La migración incorpora exclusivamente lotes reales finalizados previos, con sus ajustes. Los dos registros de existencias vacíos son catálogos operativos, no demostraciones.
- Ajustes manuales requieren UUID, variación entera no nula y motivo. Historial incluye cantidad anterior/nueva, responsable, lote/venta cuando corresponde y fecha.
- Clientes proveedores requieren productor activo no eliminado. Se preservan referencias y copias históricas ante cambios posteriores. No se permiten dos clientes para el mismo productor.
- `descontar_liquidacion` solo se admite para proveedores. En esta fase marca deuda para futura consolidación: la fase 7 debe descontar solo ventas confirmadas pendientes y proteger ventas ya vinculadas a una liquidación. Una venta pagada directamente no debe volver a descontarse.

## Precisión, concurrencia y permisos
Dinero DECIMAL(14,2), precios DECIMAL(12,2), operaciones BCMath, cantidades enteras. Subtotal = suma(cantidad × precio copiado); total = subtotal − descuento. El cliente no controla precios, totales, estado, responsable ni IDs internos de detalle. No se permiten descuentos negativos, superiores al subtotal o con más de dos decimales. Capacidad monetaria validada antes de persistir.

UUID único de venta; clave única de movimiento; detalle único por venta/tipo; productor único por cliente. Todas las escrituras comparten el bloqueo administrativo existente, luego bloquean las filas operativas y existencias. Repetir creación, confirmación, pago o anulación no duplica sus efectos. Stock no negativo protegido por comprobación dentro del bloqueo y columnas unsigned de MySQL.

Gate `administrar-ventas`: solo administrador activo. Cada acción Livewire y caso de uso reautoriza en servidor. La API añade Sanctum y middleware de usuario activo. No amplía roles contador, supervisor, recolector ni calidad.

## Panel
Ruta `/admin/ventas`, menú «Inventario y ventas».
1. Finalizar un lote desde Producción para ingresar stock, o registrar un ajuste justificado de existencias reales.
2. Registrar un cliente; para proveedor vincular un productor y definir límite/tarifa.
3. Crear una venta con uno o ambos tipos, cantidad y descuento total.
4. Consultar precios calculados y confirmar para descontar stock.
5. Registrar método y fecha de pago, o marcar compra de proveedor para la futura liquidación.
6. Anular con motivo para revertir stock; consultar la auditoría y los movimientos.

El formulario conserva datos tras errores; botones usan `spinner` y `wire:submit` normales, sin referencias DOM auxiliares ni bloqueo manual permanente. Estados vacíos indican qué falta registrar. Listas de ventas y movimientos paginadas; detalles cargados anticipadamente. Dashboard: litros procesados y moldes de lotes finalizados vigentes (incluye ajustes), rendimiento ponderado moldes × 100 / litros, alertas de rendimiento pendientes, stock por tipo y ventas confirmadas/pagadas del día. Anulaciones excluidas.

## API v1
Todas requieren `Authorization: Bearer <token>` de administrador activo y `Accept: application/json`.

| Método | Ruta | Acción |
|---|---|---|
| GET/POST | `/api/v1/ventas` | Listar (filtros estado, cliente_id, page) / crear borrador |
| GET | `/api/v1/ventas/{uuid}` | Detalle |
| POST | `/api/v1/ventas/{uuid}/confirmar` | Descontar stock |
| POST | `/api/v1/ventas/{uuid}/pagar` | Registrar pago |
| POST | `/api/v1/ventas/{uuid}/anular` | Anular con motivo |
| GET | `/api/v1/ventas/opciones` | Clientes, productores activos y stock |
| GET | `/api/v1/ventas/movimientos` | Historial paginado |
| POST | `/api/v1/ventas/clientes` | Crear cliente |
| PUT | `/api/v1/ventas/clientes/{id}` | Corregir/desactivar con motivo |
| POST | `/api/v1/ventas/tarifas` | Nueva versión |
| POST | `/api/v1/ventas/inventario/ajustes` | Ajuste auditado |

Crear venta (UUID nuevo del cliente):
```json
{"uuid":"2df0d91c-3b55-4ef7-a523-d9879f42d9c8","cliente_id":1,"vendida_at":"2026-09-09T10:00:00-05:00","descuento":"2.00","descontar_liquidacion":false,"detalles":[{"tipo":"paria_fresco","moldes":2}]}
```
Respuesta 201 (`data`): UUID, cliente histórico, estado borrador, subtotal `42.00`, descuento `2.00`, total `40.00`, moneda PEN, tarifa aplicada, detalles y metadatos. Repetición UUID: 200 y venta existente.

Pago: `{"metodo_pago":"Efectivo","pagada_at":"2026-09-09T11:00:00-05:00"}`. Anulación: `{"motivo":"Venta registrada por error"}`.

Tarifas:
```json
{"precios":{"mayorista":"20.00","proveedor":"18.00","publico_general":"21.00"},"limite_proveedor":20,"alcance_limite":"semana_jueves_miercoles","motivo":"Acuerdo administrativo documentado"}
```
El valor 20 es un ejemplo solicitado por el operador, no una configuración impuesta automáticamente.

Cliente: `{"nombre":"Nombre real","categoria":"publico_general","activo":true}`. Para proveedor añadir `productor_id`; edición añade `motivo`.

Ajuste: `{"uuid":"dbbb387d-6f45-4f67-9534-d26af70dfe90","tipo":"paria_fresco","delta":-1,"motivo":"Merma comprobada"}`.

Errores: 401 sin autenticación, 403 sin permiso/inactivo, 404 UUID no encontrado, 422 validación o conflicto de stock/cupo/estado con mensajes en español.

## Verificación
31 pruebas de inventario/ventas + producción, 151 aserciones, correctas. Concurrencia MySQL exclusiva: 1 prueba, 8 aserciones; dos ventas compiten por el último molde y solo una se confirma. Crea base aleatoria `lactocolus_test_ventas_<hex>`, migra exclusivamente allí y la elimina en finally. Nunca reutiliza la base local. SQLite se usa en pruebas funcionales y no acredita bloqueos MySQL.

Comandos:
```sh
php artisan test --compact tests/Feature/InventarioVentasTest.php tests/Feature/ProduccionTest.php
LACTOCOLUS_MYSQL_TESTS=1 php artisan test --compact tests/Feature/ConcurrentVentasMySqlTest.php
php artisan test
vendor/bin/pint --dirty --format agent
npm run build
php artisan route:list
git diff --check
```
Migración pendiente de esta fase: `2026_09_09_135437_create_inventario_ventas_tables`. Aplicar exclusivamente `php artisan migrate --no-interaction`, sin seeders, fresh ni refresh. Estado de aplicación y resultados globales en `estado-finalizacion.md`.
