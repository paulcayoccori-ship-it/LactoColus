# Ranking de calidad y Muro de Honor

## Reglas y decisiones
Puntuación de 0–100, con pesos exigidos por los requisitos: sólidos totales 40 %, densidad corregida 30 %, acidez 30 %. El repositorio no contiene una fórmula aprobada de normalización. Se implementa una tabla de bandas y puntos asignados explícitamente por el administrador para cada parámetro, sin criterios científicos/regulatorios predefinidos. El ranking comienza deshabilitado y sin bandas.

Cada banda define mínimo, máximo y puntos (0–100). Los mínimos se incluyen y los máximos se excluyen, salvo el máximo de la última banda, incluido. Deben cubrir todo el dominio de captura sin huecos ni solapamientos: sólidos 0–100 % m/m, densidad 0–2 g/mL, acidez 0–100 % de ácido láctico. Estos extremos son límites de representación/captura heredados, no rangos de leche aceptable. Los puntos los establece el administrador con referencia de aprobación y motivo, no el sistema.

Puntos del análisis = (puntos de sólidos × 40 + puntos de densidad × 30 + puntos de acidez × 30) / 100. Agregación por productor configurable: media de puntuaciones o menor puntuación. Se guarda esa elección y la versión del algoritmo `bandas_ponderadas_v1`. Cálculos BCMath, almacenamiento DECIMAL(7,4), redondeo al cuarto decimal al concluir la agregación; no float persistido.

Cualquier análisis no anulado con agua añadida > 0 pone en cero la puntuación del productor de todo el periodo. Esto también se comprueba en análisis de otras rutas del mismo productor cuando se filtra por ruta: el filtro no permite eludir la regla. No se generan sanciones ni pagos en esta fase.

Se consideran análisis no anulados, incluidos pendientes de revisión técnica: los criterios de puntuación son independientes de los criterios de conformidad y deben ser aprobados por el administrador. Si falta cualquier parámetro necesario en un análisis, el productor queda pendiente y no aparece públicamente; la regla de agua tiene prioridad y produce cero aunque falten otros parámetros. Productores sin análisis no reciben puntos inventados.

## Sólidos totales y densidad
La fase de calidad no tenía sólidos totales medidos. Se añade `analisis_calidad.solidos_totales`, decimal opcional, % m/m, con validación 0–100 y cuatro decimales, disponible en panel y API de calidad. Los registros anteriores quedan NULL y sus perfiles de conformidad no cambian. No se calcula a partir de grasa ni sólidos no grasos porque no hay una fórmula aprobada documentada. La densidad corregida sigue siendo una medición separada: su automatización permanece pendiente de validación técnica. Registrar valores reales mediante Calidad; corregir valores exige la auditoría ya existente.

## Periodos, desempates e historial
Diario: fecha indicada. Semanal: inicio configurable lunes o jueves, siete días. Mensual: mes calendario. Fecha introducida se normaliza al periodo; puede calcularse un periodo en curso con los datos disponibles. No se aceptan fechas de inicio futuras. Se guardan desde/hasta, ruta de filtro, algoritmo, copia completa de reglas, valores originales usados y resultados por productor.

Empates: puntuación descendente, código histórico de productor en orden léxico, ID interno como desempate final. Posiciones ordinales estables. La API pública no expone ese ID.

Recálculo idempotente por huella SHA-256 de periodo, alcance, versión de reglas y fuentes ordenadas. Mismos datos/reglas devuelven el mismo cálculo; datos o reglas diferentes generan otra copia sin borrar anteriores. Solo el cálculo vigente para el mismo periodo y alcance se publica. Las escrituras usan transacciones y el bloqueo administrativo compartido; huella única evita duplicación.

Registrar, corregir, revisar o anular un análisis invalida los cálculos que abarcan su fecha anterior/nueva. Se conservan fuentes y resultados históricos; dejan de estar disponibles públicamente hasta recálculo explícito. No se publican puntuaciones derivadas de un análisis que acaba de anularse. La invalidación es conservadora por periodo (puede retirar temporalmente otras rutas del mismo periodo).

## Privacidad y permisos
Solo administradores activos configuran y recalculan. Cada acción Livewire y endpoint de escritura se autoriza en servidor. Público solo lectura con límite de 60 peticiones/minuto. Identidad configurable: código (predeterminado de privacidad), nombre abreviado (primer nombre e inicial de apellido) o nombre completo. La configuración actual de privacidad se aplica también a snapshots existentes; deshabilitar ranking oculta todos los resultados públicos.

Respuesta pública únicamente: posición, etiqueta de productor, nombres/códigos históricos de rutas y puntuación; metadatos del periodo y versión. No DNI, teléfono, dirección, UUID del análisis, IDs internos de productor, datos financieros ni detalles de mediciones. Datos completos de evaluación quedan en administración. Sin aplicación móvil.

## Panel y API
- `/admin/ranking`: reglas, cálculo e historial.
- `/muro-de-honor`: consulta pública por periodo, fecha y ruta.
- `GET /api/v1/ranking?tipo=diario&fecha=2026-09-10&ruta_id=1`: público, solo lectura. Omitir ruta para conjunto completo.
- `POST /api/v1/ranking/reglas`: administrador Sanctum, nueva versión.
- `POST /api/v1/ranking/calcular`: administrador Sanctum, cálculo o recálculo idempotente.

Ejemplo de configuración **sintética para pruebas**, no criterio de negocio ni sanitario aprobado; no se carga automáticamente:
```json
{"activo":true,"privacidad":"codigo","agregacion":"media","inicio_semana":"jueves","motivo":"Ejemplo sintético en base exclusiva de pruebas","bandas":{"solidos_totales":[{"minimo":"0","maximo":"100","puntos":"80"}],"densidad_corregida":[{"minimo":"0","maximo":"2","puntos":"80"}],"acidez":[{"minimo":"0","maximo":"100","puntos":"80"}]}}
```
En operación real se deben sustituir todas las bandas y puntuaciones por criterios aprobados.

Cálculo:
```json
{"tipo":"semanal","fecha":"2026-09-10","ruta_id":null,"motivo":"Revisión semanal autorizada"}
```
Respuesta 201 o 200 idempotente: `data.uuid`, desde, hasta, algoritmo. La consulta pública devuelve:
```json
{"data":{"periodo":{"tipo":"diario","desde":"2026-09-10","hasta":"2026-09-10","calculado_at":"2026-09-10T09:00:00-05:00","version_reglas":1,"algoritmo":"bandas_ponderadas_v1"},"resultados":[{"posicion":1,"productor":"P-001","rutas":[{"codigo":"R-001","nombre":"Ruta Norte"}],"puntuacion":"80.0000"}]}}
```
Sin cálculo vigente/configuración: resultados vacíos y mensaje explicativo. 401 escritura sin token, 403 rol no autorizado, 422 bandas/fechas inválidas o criterios sin configurar; 429 límite de consultas.

## Pasos de prueba manual
1. Registrar sólidos totales medidos y densidad corregida real desde Calidad, sin inventar datos ausentes.
2. Definir bandas aprobadas para los tres parámetros; elegir agregación, calendario y privacidad; guardar nueva versión con motivo.
3. Calcular un periodo y revisar por análisis los puntos y fuentes. Pendientes no aparecen en el muro.
4. Abrir Muro de Honor y comprobar que solo expone la identidad configurada.
5. Repetir el cálculo con iguales fuentes: mantiene UUID. Cambiar reglas y recalcular: conserva el cálculo anterior.
6. Anular motivadamente una muestra: el periodo desaparece del muro hasta recálculo.

## Verificación
53 pruebas de ranking y regresión de calidad, 231 aserciones. MySQL exclusivo: una prueba concurrente con 7 aserciones, dos cálculos simultáneos producen un snapshot. Base aleatoria `lactocolus_test_ranking_<hex>` creada/migrada/eliminada exclusivamente para la prueba; nunca la base local. Pruebas SQLite no demuestran bloqueos MySQL.

Migración `2026_09_10_130920_create_ranking_tables`: sólidos totales opcionales, cálculos, resultados y fuentes con FK restrictivas e índices únicos. Solo migraciones pendientes. Resultado global y aplicación local en `estado-finalizacion.md`.

```sh
php artisan test --compact tests/Feature/RankingTest.php tests/Feature/CalidadTest.php
LACTOCOLUS_MYSQL_TESTS=1 php artisan test --compact tests/Feature/ConcurrentRankingMySqlTest.php
```
