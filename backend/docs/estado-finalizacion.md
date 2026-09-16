# Estado de finalización del backend

## Alcance y conservación
Solo backend/app, config, database, resources, routes, tests, docs y documentación raíz/Graphify. No modificar mobile ni hacer commit/push. Estado inicial: cambios sin commit de control de calidad (conservados). SRS completo no localizado; consulta al usuario sobre ubicación pendiente. Se usan los requisitos detallados de su mensaje, sin inventar reglas faltantes.

## Fases
- Fase 0: completada y verificada. 35 pruebas de calidad, 161 aserciones; concurrencia MySQL exclusiva 1 prueba, 8 aserciones. Se corrigió una barrera de test que perdía READY si el proceso iniciaba antes de waitUntil.
- Fase 1: completada y verificada (producción de queso).
- Fase 2: completada y verificada (inventario y ventas).
- Fase 3: completada y verificada (comunicados).
- Fase 4: completada y verificada (solicitudes de traslado).
- Fase 5: completada y verificada (ranking de calidad).
- Fase 6: completada y verificada (penalizaciones por calidad).
- Fase 7: actual (periodos y liquidaciones semanales).
- Fases 8–9: pendientes.

## Archivos y migraciones
Cambios previos de calidad conservados. Cambio actual: tests/Feature/ConcurrentCalidadMySqlTest.php (barrera robusta con salida acumulada). Migración de calidad ya aplicada previamente en lote 9; nueva migración 2026_09_09_133115_create_produccion_tables aplicada en lote 10.

## Verificación
Calidad funcional y concurrencia MySQL pasan. Graphify consultado y actualizado en fase 0. Prueba MySQL usa solo base aleatoria exclusiva, nunca la local. No seeders locales ni cambios de contraseña.

## Fase 1 completada
Nuevos módulos Application/Produccion, Application/Operacion, Infrastructure/Produccion, Infrastructure/Operacion, config/operacion.php, API ProduccionController/Request/Resource, página produccion, factories y pruebas. Guardas en GestionarRecepciones impiden anulación/reducción de litros ocupados. No borrar esos cambios.

Suite completa: 156 pruebas, 149 correctas, 7 omitidas, 815 aserciones. Pruebas producción + recepción: 26/92. Concurrencia MySQL producción: 1/7, base aleatoria eliminada al terminar. Build, Pint, rutas y diff correctos.

## Fase 2 completada
Servicios Inventario/Ventas, modelos, API VentasController/Request/Resource, página ventas, tarifas en config/operacion, integración transaccional de producción, dashboard, factory Cliente y pruebas. Migración 2026_09_09_135437_create_inventario_ventas_tables aplicada local (lote 11). Documentación docs/inventario-ventas.md.

Pruebas específicas: 31/151 correctas. Suite completa: 171 pruebas, 163 correctas, 8 omitidas, 897 aserciones. Concurrencia MySQL ventas exclusiva: 1/8 correcta. Build, Pint, route:list y diff sin errores. Build avisa que fontaine es opcional; no se cambian dependencias. Graphify actualizado: 1859 nodos, 3308 aristas.

## Fase 3 completada
Application/Comunicados, Infrastructure/Comunicados, API, panel, comando programado, rol productor y vinculación auditada usuario/productor. Nuevo rol productor aislado del padrón API global sin modificar permisos internos anteriores. Migración 2026_09_09_141320_create_comunicados_tables aplicada local (lote 12). docs/comunicados.md.

32 pruebas específicas con regresión de productores, 199 aserciones; MySQL lecturas simultáneas 1/6 correcta. Suite 185 pruebas: 176 correctas, 9 omitidas, 975 aserciones. Build, rutas (72), Pint y diff correctos. Graphify actualizado.

## Fase 4 completada
Application/Traslados, Infrastructure/Traslados, IdentidadProductor, API, panel, comando programado. Configuración sin plazo automático y versiones copiadas. Historial único de aplicación, actualización de una sola asignación y compactación de orden. Migración 2026_09_09_193330_create_traslados_tables aplicada local (lote 13). docs/solicitudes-traslado.md.

45 pruebas específicas con rutas, 264 aserciones. MySQL exclusivo: 2 escenarios, 17 aserciones. Suite completa: 200 pruebas, 189 correctas, 11 omitidas, 1053 aserciones. Build, Pint, route:list (79) y diff correctos. Graphify actualizado.

## Fase 5 completada
Application/Ranking, Infrastructure/Ranking, API, panel y Muro de Honor público. Bandas de puntos configurables sin normalización inventada; pesos 40/30/30, agua >0 pone a cero todo el periodo, privacidad configurable, fuentes y snapshots inmutables. Calidad añade sólidos totales medidos opcionales; cambios invalidan snapshots públicos. Migración 2026_09_10_130920_create_ranking_tables aplicada local (lote 14). docs/ranking-calidad.md y extensión control-calidad.md.

53 pruebas específicas con calidad, 231 aserciones. MySQL exclusivo 1/7 correcto (dos recálculos, un snapshot). Suite completa: 219 pruebas, 207 correctas, 12 omitidas, 1123 aserciones. Build, Pint, route:list (84) y diff correctos. Graphify actualizado.

## Fase 6 completada
Application/Penalizaciones, Infrastructure/Penalizaciones, API y panel. Detección desde Calidad, propuestas sin expulsión automática, tarifas sin valor predeterminado, alcance/reincidencia configurables y versionados, asistencia por acidez con criterios vigentes, recálculo administrativo motivado e invalidación por cambios de fuente. Migración 2026_09_10_132310_create_penalizaciones_tables aplicada local (lote 15). docs/penalizaciones-calidad.md.

51 pruebas específicas con calidad, 238 aserciones. MySQL exclusivo: 1/7 correcto. Suite completa: 236 pruebas, 223 correctas, 13 omitidas, 1200 aserciones. Build, Pint, route:list (92) y diff correctos. Graphify actualizado.

## Siguiente acción exacta
Implementar fase 7: periodos jueves–miércoles, pago previsto viernes, tarifas 1.70 versionadas; cierre congela fuentes; liquidaciones por productor con litros diarios, bonos, penalizaciones aprobadas, descuentos de queso y ajustes. Admin configura/aprueba, contador calcula/revisa/paga, productor consulta solo identidad propia mediante IdentidadProductor. Pagos idempotentes y comprobante PDF/imprimible con marca.

## Integración necesaria en fase 7
- Ventas marcadas descontar_liquidacion: solo confirmadas pendientes; impedir pago directo/anulación silenciosa si ya están vinculadas a liquidación cerrada/aprobada; liberar únicamente mediante anulación/ajuste auditado apropiado.
- Sanciones: solo aprobadas y sin requiere_revision; copiar tarifa, alcance (entrega/día/semana), fuente y decisiones. Recálculo de sanción nunca altera copias financieras congeladas; futuros cambios mediante ajustes auditados. Pérdida de liquidación solo si decision_perdida aprobada; propuestas de expulsión no cambian estado del productor.
- Congelar acopios del periodo contra correcciones, anulaciones y sincronizaciones tardías no autorizadas; no modificar datos fuente después del cierre. Revisar GestionarAcopios.
- No hay librería PDF instalada entre dependencias directas: revisar herramientas/dependencias existentes antes de decidir exportación, no instalar sin aprobación.

## Notas previas
Fase 1 implementó: lotes y asignación decimal de litros disponibles de recepciones, rango versionado 11–12 moldes/100 L indicado por el usuario, congelación, ajustes/auditoría, API y panel. Probar y migrar únicamente pendientes antes de avanzar a fase 2.
