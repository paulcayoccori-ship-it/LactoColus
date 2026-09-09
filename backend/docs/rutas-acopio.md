# Tercera entrega: rutas de acopio

## Alcance y decisiones provisionales

Módulo web de Laravel 13, Livewire 4 y MaryUI 2, con las dependencias existentes y MySQL como base de la aplicación. Se revisaron AGENTS.md, las reglas `.ai/rules`, las entregas de productores y panel administrativo y la corrección del formulario de usuarios. No se encontró un SRS en el repositorio ni se utilizaron adjuntos externos.

Decisiones a revisar cuando exista una especificación definitiva:

- Una ruta tiene cero o un recolector responsable. Un recolector puede responsabilizarse de varias rutas.
- Un productor tiene como máximo una ruta actual, incluso si esa ruta está inactiva. No se transfieren productores automáticamente: se debe retirar primero la asignación anterior.
- Las nuevas incorporaciones se agregan al final. Subir/Bajar establece un orden consecutivo desde 1; retirar un productor compacta el orden.
- Una ruta inactiva conserva sus asignaciones y sigue siendo editable por administradores, incluida la preparación de sus asignaciones. No hay eliminación física de rutas.
- Desactivar un productor, eliminarlo mediante el soft delete existente, desactivar al responsable o retirarle el rol recolector conserva la referencia. El detalle advierte que ya no está disponible; los selectores excluyen esas personas.
- El código es único, de hasta 50 caracteres; nombre obligatorio de hasta 150; descripción opcional de hasta 5000. Se recortan espacios de los extremos. La unicidad en MySQL sigue la intercalación configurada en la base.

No se añadieron recolecciones, sensores, calidad, pagos, GPS, mapas, distancias ni tiempos. No se modificaron mobile, las dependencias ni los contratos de API existentes.

## Uso

1. Iniciar sesión con una cuenta administradora activa y abrir **Rutas de acopio** en el menú adaptable.
2. Usar **Nueva ruta**, completar código y nombre, descripción opcional y estado; pulsar **Guardar**. Los errores aparecen en español y permiten corregir y volver a guardar.
3. Buscar por código/nombre y combinar con el filtro de estado. El listado pagina de 15 en 15 y muestra responsable y número de productores, incluidos los asignados que posteriormente quedaron inactivos o eliminados.
4. **Consultar** abre el detalle debajo del listado. El lápiz permite editar los datos. **Activar/Desactivar** solicita confirmación y conserva todas las asignaciones.
5. En el detalle, elegir un recolector activo y **Guardar responsable** para asignarlo o reemplazarlo; **Retirar responsable** deja la ruta sin asignar. Si no hay candidatos, revisar Usuarios.
6. Elegir un productor activo sin ruta y pulsar **Incorporar productor**. Si otra petición lo asignó mientras el formulario estaba abierto, se informa el conflicto y la ruta anterior; no se sobreescribe la asignación.
7. Usar **Subir/Bajar** para ordenar las visitas y **Retirar** para liberar al productor. Si no hay candidatos, revisar el padrón o retirar primero su asignación en otra ruta.
8. Revisar advertencias de personas que perdieron disponibilidad. El dashboard muestra el número real de rutas activas.

URL resuelta con Laravel Boost: http://localhost:8000/admin/rutas. Dashboard: http://localhost:8000/admin/dashboard. Si no hay servidor iniciado, ejecutar `php artisan serve` desde `backend`; los recursos ya se compilaron con `npm run build`.

## Arquitectura, autorización y concurrencia

`Domain/Rutas/RutaRepository` define el contrato y devuelve arrays/paginación. `Application/Rutas/ConsultarRutas` y `GestionarRutas` contienen consultas autorizadas, validaciones y decisiones de negocio. `Infrastructure/Rutas` implementa Eloquent y las relaciones; el componente `pages::rutas.index` presenta los datos y coordina los formularios MaryUI.

La ruta HTTP exige `auth` y el Gate `administrar-rutas`. El componente autoriza cada petición en `boot`; todas las acciones de negocio se autorizan explícitamente y las escrituras vuelven a comprobar el administrador dentro de la transacción. Los identificadores de edición y detalle están protegidos con `#[Locked]`; los IDs recibidos en acciones se verifican contra la ruta seleccionada. Los demás roles no reciben permisos nuevos.

Las escrituras reutilizan `UsuarioRepository::underAdminLock`, que bloquea la fila `administrador/web` y reintenta hasta cinco veces ante interbloqueos. Esto coordina rutas y cambios administrativos de usuarios, incluida la pérdida del rol recolector. Se bloquea además la ruta y se lee el productor o recolector elegido con `FOR UPDATE` antes de aceptar la asignación. Los cambios existentes de estado o soft delete del productor también compiten por su fila InnoDB. Una desactivación posterior conserva la referencia y pasa a mostrarse como advertencia.

La tabla `ruta_productor` tiene `productor_id` como clave primaria (una sola ruta por productor), clave única `(ruta_id, orden)` y claves foráneas restrictivas. `rutas_acopio.codigo` es único; `recolector_id` es nullable y referencia `users`. No se añaden cascadas de eliminación. Al reordenar se usan posiciones temporales libres dentro de la transacción para evitar colisiones transitorias del índice único. Los conflictos de unicidad se traducen a errores españoles y cualquier fallo revierte la operación.

La serialización administrativa global es una decisión conservadora que reutiliza el mecanismo existente. Si el volumen de escrituras crece, revisar su contención antes de sustituirlo; todos los caminos de escritura relacionados deberán conservar un protocolo de bloqueo compatible.

El listado usa carga anticipada de responsables y roles y `withCount` de productores; una prueba comprueba que el número de consultas no crezca por cada fila. El detalle conserva productores eliminados mediante `withTrashed`. Los formularios usan acciones directas `wire:submit` y estados de carga de Livewire, sin referencias DOM ni un flag persistente de guardado. Se prueban tanto el HTML del formulario como el reintento tras fallos. Los fallos de base de datos se registran y muestran un mensaje seguro en español.

## Migración local

Aplicada únicamente la migración pendiente `2026_09_09_002438_create_rutas_acopio_tables`, con `php artisan migrate --no-interaction`. Crea `rutas_acopio` y `ruta_productor`; no altera las filas existentes.

Se comparó el contenido completo de las tablas antes/después mediante resúmenes calculados en memoria: **2 usuarios, 22 productores, 4 roles y 2 asociaciones de roles conservados íntegramente**. Las rutas nuevas quedaron en cero. No se cambiaron contraseñas, no se borraron registros locales ni se ejecutaron seeders, `migrate:fresh` o `migrate:refresh` contra la base local.

## Pruebas y verificación

Desde `backend`:

```sh
php artisan test
LACTOCOLUS_MYSQL_TESTS=1 php artisan test --compact tests/Feature/ConcurrentRutasMySqlTest.php
npm run build
php artisan route:list
vendor/bin/pint --dirty --format agent
git diff --check
```

`RutasAcopioTest` cubre altas, consultas, edición, activación/desactivación, validación y unicidad, filtros/paginación, dashboard real, roles y acciones revocadas, administrador inactivo, selección de responsables, reemplazo/retiro, pérdida de disponibilidad, productores inactivos/eliminados, conflictos entre rutas, duplicados, orden y límites, conservación de relaciones, alcance de acciones, escape HTML, recuperación del formulario y consultas sin N+1. Las pruebas previas de login, productores, usuarios y dashboard se conservan.

**Resultado final:** `php artisan test`: 80 aprobadas, 529 aserciones y 3 optativas omitidas; ejecución MySQL separada: 3 aprobadas, 35 aserciones.

La suite habitual fuerza SQLite en memoria en `phpunit.xml` y omite las tres pruebas optativas MySQL. SQLite no demuestra los bloqueos MySQL.

`ConcurrentRutasMySqlTest` se ejecutó además contra **MySQL/InnoDB real**, con dos procesos PHP solapados. Verifica que la segunda petición espere mientras la primera mantiene el bloqueo y comprueba los resultados persistidos para:

- Un mismo productor en dos rutas: una asignación y un conflicto.
- Un mismo productor en la misma ruta: una asignación y un conflicto.
- Dos productores en la misma ruta: ambas asignaciones con órdenes 1 y 2.

Cada caso crea una base nueva aleatoria `lactocolus_test_rutas_<16 caracteres hexadecimales>`, sin reutilizar bases existentes. Se comprueba su nombre y motor, se ejecutan migraciones solo allí y se elimina únicamente esa base creada por el propio caso al finalizar. La conexión administrativa no selecciona ninguna base. La ejecución optativa necesita acceso MySQL y permiso para crear/eliminar bases de pruebas; si falla, corregir ese acceso, nunca apuntarla a la base local de la aplicación. No cambia `phpunit.xml` ni `.env`.

La compilación terminó correctamente con el aviso opcional preexistente sobre `fontaine`; no se instaló. `route:list` muestra 34 rutas, incluida `admin.rutas`. Pint y `git diff --check` pasan. Se ejecutó `graphify update .` desde la raíz: al no existir el grafo inicial, generó el grafo AST sin API/LLM.

No había navegadores conectados. Queda pendiente la comprobación visual interactiva de escritorio/móvil y consola JavaScript; la renderización, el HTML y las acciones están cubiertos por pruebas HTTP/Livewire. No hubo commits ni push.
