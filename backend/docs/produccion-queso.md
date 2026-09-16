# Producción de queso Paria — fase 1

## Reglas y decisiones

Solo administradores activos gestionan lotes y reglas por web/API (Gate `administrar-produccion`). Tipos: `paria_fresco` y `paria_pasteurizado`. Cada lote tiene UUID idempotente, código único, fecha/hora, tipo, recepciones y litros, responsable autenticado, estado y observaciones. El servidor suma las fracciones asignadas de cada recepción: no acepta un total arbitrario de litros.

Estados: `borrador` → `en_proceso` → `finalizado`; cualquiera puede anularse con motivo. Un borrador reserva leche. Iniciar lo convierte en consumo. Anular un borrador libera su reserva; anular un lote ya iniciado NO convierte leche físicamente procesada en disponible. No se autorizan sobregiros ni reutilización de litros consumidos. Una recepción puede abastecer varios lotes mediante fracciones distintas de su saldo, sin duplicar litros.

La fecha del lote admite planificación futura. Las recepciones deben estar vigentes (no anuladas). Se conserva autor original y todas las referencias. Las correcciones de código, tipo, fecha y observaciones del borrador requieren motivo. Para cambiar sus fuentes debe anularse ese borrador y crear otro; el historial de asignación permanece.

## Rendimiento y ajustes

Rendimiento = moldes × 100 / litros de cuba. Se almacena el cociente con seis decimales; para decidir fuera de rango se comparan productos decimales sin redondear el cociente. Rango inicial **11–12 moldes por 100 litros**, indicado explícitamente en los requisitos del usuario. No se encontró el archivo SRS independiente. No es una fórmula científica inventada ni una atribución causal al productor.

`config/operacion.php` define la versión inicial 0; la pantalla crea versiones nuevas en `reglas_operativas`, con motivo/autor. Cada lote conserva copia de la versión elegida al crearlo. Los cambios posteriores no lo reclasifican automáticamente.

Al finalizar se congela el registro original. Se permite cero moldes para registrar una producción fallida, con alerta. Un ajuste posterior añade una fila `ajustes_produccion` con delta entero de moldes, UUID único, autor, fecha y motivo; nunca sobrescribe el dato original. El total efectivo no puede ser negativo. La API publica tanto original como efectivo. Repetir el UUID de un ajuste devuelve el resultado existente, sin aplicarlo otra vez.

Se crea una alerta por lote cuando el rendimiento efectivo sale del rango. Un ajuste que lo vuelve a ubicar dentro del rango resuelve la alerta conservándola; uno que lo saca reabre la misma alerta. Anular la resuelve conservando historial. No se atribuye la desviación a ningún productor. No existen descuentos ni sanciones automáticas.

## Persistencia y seguridad

Migración `2026_09_09_133115_create_produccion_tables.php`: `lotes_produccion`, `usos_recepcion`, `ajustes_produccion`, `alertas_rendimiento`, `reglas_operativas` y `auditorias_operativas`. UUID/código/versiones/usos tienen restricciones únicas y relaciones restrictivas sin cascadas de borrado. Las cantidades de litros son DECIMAL(12,3); no hay dinero ni cálculo persistido con float.

Las escrituras usan `UsuarioRepository::underAdminLock`, reautorizan al actor y bloquean lote y recepciones en orden de ID. La suma de usos reservados/consumidos se comprueba bajo el bloqueo de la recepción. Se coordinan también las correcciones/anulaciones de recepciones: no se pueden reducir sus litros por debajo de los asignados ni anular mientras haya reservas/consumos. Las filas operativas no se eliminan físicamente.

`AuditarOperacion` conserva acciones y antes/después. El caso de uso contiene transacciones y reglas; controladores/Livewire validan entrada, presentan resultados y llaman servicios. Las tablas cargan responsable/alerta y sumas de ajustes anticipadamente; el detalle carga fuentes y auditoría. El formulario usa acciones directas y estados de petición, sin referencias DOM.

## Panel y API

Panel: `/admin/produccion`. Lista paginada por código, tipo y estado. Permite crear, consultar fuentes y auditoría, corregir borrador, iniciar, finalizar, ajustar, anular y versionar rango.

Sanctum y administrador activo en todos los endpoints:

- `GET /api/v1/produccion/lotes?buscar=LOTE&tipo=paria_fresco&estado=finalizado`
- `POST /api/v1/produccion/lotes`
- `GET /api/v1/produccion/lotes/{uuid}`
- `POST /api/v1/produccion/lotes/{uuid}/iniciar`
- `POST /api/v1/produccion/lotes/{uuid}/finalizar` con `moldes` entero.
- `POST /api/v1/produccion/lotes/{uuid}/ajustar` con `uuid`, `delta_moldes`, `motivo`.
- `POST /api/v1/produccion/lotes/{uuid}/anular` con `motivo`.
- `POST /api/v1/produccion/lotes/{uuid}/corregir` con código/tipo/fecha/observaciones y motivo (borrador).
- `POST /api/v1/produccion/reglas` con `minimo`, `maximo`, `motivo`.

Ejemplo de creación:

```json
{
  "uuid": "eb7c1a94-5a9c-4ce2-bd0c-534075e0c462",
  "codigo": "PARIA-20260909-01",
  "tipo": "paria_fresco",
  "producido_at": "2026-09-09T08:30:00-05:00",
  "recepciones": [{"recepcion_id": 1, "litros": "100.000"}],
  "observaciones": "Cuba 1"
}
```

Responde `201` (`200` si ese UUID ya existe), con `data.uuid`, `codigo`, `tipo`, `litros_cuba`, moldes/rendimiento originales y efectivos, estado, responsable, rango aplicado, usos, ajustes y alerta. Inicio/finalización repetidos conservan resultado sin duplicar auditoría; finalizar nuevamente con otro conteo exige ajuste. Errores: 401 sin sesión/token, 403 por permisos, 404 por UUID ausente, 422 por validación/estado/saldo.

## Verificación y uso

`ProduccionTest` cubre suma de fuentes, saldo insuficiente, UUID, rendimiento y extremos del rango, congelación, ajuste idempotente/auditoría, anulación y consumos, guardas de recepción, versionado, permisos y recuperación del formulario. `ConcurrentProduccionMySqlTest` crea una base temporal exclusiva, sincroniza dos procesos y prueba que dos reservas de 80 L contra 100 L producen un creado, un rechazado y solo 80 L ocupados. Se elimina únicamente esa base temporal. SQLite se usa para pruebas funcionales, no como evidencia de bloqueos MySQL.

1. Registrar una recepción vigente con litros disponibles.
2. Crear un lote y seleccionar litros de una o varias recepciones.
3. Iniciar para marcar el consumo; finalizar indicando moldes.
4. Comprobar rendimiento y posible alerta sin atribución al productor.
5. Ajustar moldes con motivo y comprobar que se preserva el conteo original y la auditoría.
6. Crear otro borrador con saldo insuficiente para verificar el rechazo.

El ingreso de stock al finalizar se integra en la fase 2; esta fase establece los eventos de negocio y el historial de producción necesarios. Sin datos de demostración ni modificaciones de mobile.

Resultado de cierre de fase 1: suite completa 156 pruebas, 149 correctas, 7 omitidas, 815 aserciones. Producción y regresión de recepciones: 26 pruebas/92 aserciones. MySQL exclusivo: 1 prueba/7 aserciones. Pint, build, rutas y diff correctos. Migración de producción aplicada en lote 10. Graphify actualizado.
