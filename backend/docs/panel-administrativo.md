# Segunda entrega: panel administrativo

## Alcance

Panel en español con marca LactoColus, menú lateral para escritorio y móvil, nombre y roles de la cuenta, cierre de sesión y enlaces condicionados por autorización. Solo incluye Dashboard, Productores y Usuarios.

El inicio y `/admin` redirigen al dashboard. El login web también redirige a `/admin/dashboard`. Los roles `supervisor`, `recolector` y `contador` reciben una página 403 con cierre de sesión; no hay ciclos de redirección ni nuevos permisos para ellos. Conservan el acceso autenticado a la API de productores de la primera entrega.

El dashboard muestra productores no eliminados, activos e inactivos, total de usuarios (incluidos los inactivos) y cinco productores recientes, ordenados por fecha de creación e ID descendentes. No contiene estadísticas de leche, ventas ni pagos.

Usuarios permite búsqueda por nombre/correo, filtros combinados por rol y estado y paginación de 15 registros. Permite crear y editar nombre, correo y varios roles existentes del guard `web`: `administrador`, `supervisor`, `recolector` y `contador`. No crea roles ni elimina cuentas físicamente. El correo se normaliza a minúsculas y debe ser único.

La contraseña es obligatoria al crear y opcional al editar, exige confirmación y un mínimo de 8 caracteres, con máximo de 72 bytes por bcrypt. Dejarla vacía al editar conserva el hash existente. Los campos de contraseña permanecen en el navegador y se envían como argumentos de la acción: no se incluyen en las propiedades públicas ni snapshots de Livewire. Las consultas de usuarios devuelven únicamente atributos de presentación, nunca hashes. No hay registro público ni API administrativa de usuarios.

## Arquitectura y seguridad

- `Domain/Dashboard` y `Domain/Usuarios` definen contratos; `Application` contiene consultas, validación y reglas de negocio; `Infrastructure` implementa Eloquent. Se conserva la estructura y los contratos de productores.
- Gates protegen rutas, arranque de componentes y acciones Livewire. La autorización consulta el estado actual de la cuenta y sus roles, sin confiar en datos enviados por el navegador ni relaciones obsoletas.
- `GuardarUsuario` centraliza las escrituras administrativas. Impide desactivar la propia cuenta tanto desde el botón de estado como desde el formulario manipulado.
- Las escrituras se serializan dentro de una transacción mediante un bloqueo exclusivo de la fila del rol `administrador`/`web`. Se releen actor, cuenta destino y administradores activos bajo el bloqueo. No se permite quitar el rol ni desactivar al último administrador activo. Los administradores inactivos no cuentan para esta protección.
- El bloqueo comienza con una escritura idempotente para que también funcione en SQLite, que ignora `FOR UPDATE`. Hay reintentos de transacción para conflictos de concurrencia. Los futuros cambios administrativos deben pasar por el mismo caso de uso.
- Desactivar incrementa `auth_version`, rota el token de recordar sesión y elimina todos los tokens Sanctum dentro de la transacción. El middleware `EnsureActiveUser` valida cada petición web y API, incluidas las actualizaciones Livewire. Las sesiones antiguas quedan bloqueadas aunque se reactive la cuenta; no depende de borrar filas del almacén de sesiones. Al volver a intentar usarlas se invalidan y se exige login.
- El login API bloquea la fila del usuario mientras valida y emite el token, para coordinarse con una desactivación concurrente. Los contratos JSON y rutas de productores se mantienen.
- Una petición que ya pasó la autorización antes de una desactivación puede terminar; las siguientes peticiones se rechazan. Las escrituras administrativas vuelven a autorizar dentro de su transacción.

## Migración y datos locales

Aplicada: `2026_09_08_191202_add_active_and_auth_version_to_users_table`.

Añade `users.active` con valor predeterminado `true` y `users.auth_version` con valor predeterminado `0`. Mantiene activas las cuentas existentes sin alterar contraseñas, correos ni roles. Se verificó que antes y después de migrar se conservan 1 usuario y 21 productores en la base local; el usuario permanece activo.

Se ejecutó únicamente `php artisan migrate --no-interaction` para las migraciones pendientes. No se ejecutaron seeders, `migrate:fresh`, `migrate:refresh` ni eliminaciones de datos locales. Una reversión de esta migración quitaría las columnas de seguridad; no debe hacerse manteniendo desplegado este código.

No se instalaron ni actualizaron dependencias. Versiones PHP confirmadas: Laravel 13.30.1, Livewire 4.4.4, MaryUI 2.9.10, Sanctum 4.3.3 y Spatie Permission 8.3.0. Se reutilizan Tailwind 4 y el proceso Vite existente.

## Archivos principales

| Área | Archivos |
| --- | --- |
| Dashboard | `app/Domain/Dashboard/DashboardRepository.php`, `app/Application/Dashboard/ConsultarDashboard.php`, `app/Infrastructure/Dashboard/EloquentDashboardRepository.php`, `resources/views/pages/⚡dashboard.blade.php` |
| Usuarios | `app/Domain/Usuarios/UsuarioRepository.php`, `app/Application/Usuarios/ConsultarUsuarios.php`, `app/Application/Usuarios/GuardarUsuario.php`, `app/Infrastructure/Usuarios/EloquentUsuarioRepository.php`, `resources/views/pages/usuarios/⚡index.blade.php` |
| Autenticación y autorización | `app/Models/User.php`, `app/Http/Middleware/EnsureActiveUser.php`, controladores `Auth/SessionController.php` y `Api/V1/AuthController.php`, ambos providers, `bootstrap/app.php` |
| Navegación y presentación | `routes/web.php`, `routes/api.php`, `config/app.php`, `app/View/Components/AppBrand.php`, `resources/views/layouts/app.blade.php`, `resources/views/errors/403.blade.php`, `resources/views/pagination.blade.php` y página de productores para reutilizar la paginación española |
| Persistencia | Migración `2026_09_08_191202_add_active_and_auth_version_to_users_table.php` |
| Pruebas | `PanelAdministrativoTest.php`, `ConcurrentAdministratorsTest.php`, `UsuariosMigrationTest.php`; expectativas de redirección actualizadas en `ProductoresTest.php` y `ExampleTest.php`; `phpunit.xml` |
| Reglas y documentación | `.ai/rules/index.md`, `.ai/rules/app.md`, este documento |

## Verificación automática ejecutada

```sh
php artisan test
npm run build
php artisan route:list
vendor/bin/pint --dirty --format agent
git diff --check
```

Resultado: **47 pruebas aprobadas, 330 aserciones**. Cubren dashboard vacío y con datos, soft deletes, orden y límite de recientes, autorización por rol, creación/edición, validación, hashes y contraseña opcional, filtros/paginación, escape HTML, desactivación web/API/Livewire, revocación de tokens, reactivación, protección de la propia cuenta y del último administrador, migración sobre cuentas previas y regresiones del CRUD/API de productores.

`phpunit.xml` fuerza `testing` y SQLite en memoria. La prueba de migración usa una conexión adicional en memoria. Las dos pruebas concurrentes usan procesos PHP independientes y un archivo SQLite temporal exclusivo que se elimina al terminar; prueban retirada simultánea de roles y desactivaciones cruzadas. Ninguna prueba accede a MySQL local. No se probó concurrencia contra un servidor MySQL aislado.

La compilación terminó correctamente. Vite emitió el aviso opcional existente sobre `fontaine` para optimizar fuentes de respaldo; no se añadió esa dependencia. `route:list` mostró 33 rutas, incluidas las tres páginas administrativas y las rutas previas de productores. Pint y la comprobación de espacios finalizaron sin errores.

No hubo navegador conectado para validar visualmente los tamaños de pantalla ni la interacción Alpine. No había registros de navegador disponibles. La renderización y las acciones del servidor sí están cubiertas por HTTP/Livewire; queda realizar la siguiente revisión visual manual.

## Prueba manual

Si el servidor no está iniciado, ejecutar `php artisan serve` desde el backend. La compilación ya está generada; para desarrollo continuo se puede usar `npm run dev`. No hace falta ejecutar seeders.

Direcciones resueltas por Laravel Boost para esta configuración:

- Login: http://localhost:8000/login
- Dashboard: http://localhost:8000/admin/dashboard
- Productores: http://localhost:8000/admin/productores
- Usuarios: http://localhost:8000/admin/usuarios

1. Entrar con las credenciales actuales de un administrador. Comprobar redirección al dashboard, marca LactoColus, nombre, rol y menú. Verificar el menú móvil, la navegación por teclado y el desplazamiento horizontal de las tablas en una pantalla estrecha.
2. Comparar los totales con el padrón de productores y confirmar que no aparecen registros eliminados. Abrir los accesos a Productores y Usuarios.
3. Comprobar el CRUD de productores: crear un registro de prueba, editarlo, abrir su detalle y eliminarlo mediante el soft delete existente. Estos cambios manuales sí afectan la base local; usar registros claramente identificados como pruebas.
4. Crear una cuenta de prueba desde Usuarios. Comprobar los errores de campos vacíos, correo repetido o inválido, contraseña corta y confirmación distinta. Guardar una cuenta válida con un rol existente. Buscarla por nombre y correo y combinar filtros de rol/estado. Comprobar paginación cuando haya más de 15 coincidencias.
5. Editar nombre y roles dejando las contraseñas vacías: la contraseña anterior debe seguir funcionando. Después cambiarla con confirmación y comprobar el nuevo login. El formulario de edición debe abrir siempre las contraseñas vacías.
6. Abrir una segunda sesión en una ventana privada con la cuenta de prueba. Obtener también un token mediante `POST /api/v1/login` y comprobar `GET /api/v1/productores` con `Authorization: Bearer <token>`. No compartir ni guardar ese token en documentación.
7. Desde el administrador, desactivar la cuenta de prueba. Su siguiente petición web debe exigir login; el token previo y los nuevos intentos de login API deben recibir 401. Si era administrador y tenía una página Livewire abierta, sus acciones también deben rechazarse.
8. Reactivar la cuenta: verificar que la contraseña se conserva y permite iniciar una sesión nueva. Las sesiones y tokens anteriores no deben recuperarse.
9. En la propia cuenta, comprobar que no se puede desactivar. Con un solo administrador activo, intentar sustituir su rol por otro: debe aparecer el error de último administrador. Con dos administradores activos, retirar el rol propio es válido y termina en acceso denegado para el panel. Mantener siempre otra cuenta administrativa accesible al hacer esta prueba.
10. Entrar con supervisor, recolector y contador. Abrir directamente las tres páginas administrativas: deben responder 403 con opción de cerrar sesión y sin bucles. No deben aparecer módulos ni permisos nuevos.
11. Cerrar sesión y confirmar que las rutas administrativas exigen volver a entrar.

Los módulos de leche, ventas y pagos, el registro público y la API de administración de usuarios quedan fuera de esta entrega. No se modificó `mobile`, ni se hicieron commits, push o publicación.
