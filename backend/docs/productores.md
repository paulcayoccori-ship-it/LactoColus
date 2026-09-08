# Primera entrega: productores

## Desarrollo local

La credencial `admin@lactocolus.test` / `password` es **solo de prueba local**, nunca de producción. El seeder DevelopmentSeeder solo actúa en local/testing; no cambia contraseñas de usuarios existentes. Crea cuatro roles con guard web y 20 productores. Es repetible y respeta registros eliminados.

Ejecutar `php artisan migrate` y `php artisan db:seed --class=DevelopmentSeeder`. No se requiere recrear tablas.

Web: GET /login, POST /login, POST /logout y GET /admin/productores (administrador). El inicio de sesión regenera la sesión; salir invalida la sesión y regenera CSRF. Hay límite de cinco intentos por minuto por correo e IP.

API: POST /api/v1/login recibe email, password y device_name opcional. Devuelve data.token y data.token_type. Enviar Authorization: Bearer TOKEN en productores y logout. POST /api/v1/logout revoca exclusivamente el token actual y responde 204.

GET/POST /api/v1/productores; GET/PUT/PATCH/DELETE /api/v1/productores/{id}. GET acepta search, estado=0|1, page y per_page (1–100, 15 por defecto). PUT exige los campos obligatorios; PATCH permite cambios parciales. DELETE responde 204 sin cuerpo y aplica soft delete. Código, DNI y correo siguen reservados después de eliminar.

Crear: codigo, dni (cadena de 8 dígitos), nombres y apellidos obligatorios; celular (cadena de 9 dígitos), email, direccion y comunidad opcionales; estado booleano por defecto true. Se preservan ceros iniciales. La API admite cualquier usuario autenticado, mientras el panel exige administrador.

JSON exitoso: message y data; listados incluyen links y meta de paginación. Errores: message, data=null y errors para 422. HTTP: 200 consultas/cambios/login, 201 creación, 204 eliminación/logout, 401 sin autenticación/credenciales inválidas, 403 sin autorización, 404 ausente/eliminado, 422 datos inválidos, 429 límite de acceso.

Arquitectura práctica: Domain define el contrato; Application contiene DTO, validación compartida y cinco casos de uso; Infrastructure encapsula Eloquent. El contrato devuelve arrays y usa el contrato de paginación de Laravel, sin exponer modelos Eloquent. ProductorServiceProvider registra repositorio, autorización web y límite de login. Controladores/Form Requests/Resource presentan la API; la página Livewire presenta la web y autoriza cada petición y acción.

Verificación: `php artisan test` usa SQLite en memoria según phpunit.xml, sin tocar MySQL; `npm run build`; `php artisan route:list`.
