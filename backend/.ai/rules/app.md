---
paths:
  - 'app/**'
---

# App

## Cambios de roles y estado de usuarios
Las escrituras administrativas de usuarios pasan por Application/Usuarios/GuardarUsuario y UsuarioRepository::underAdminLock. El bloqueo compartido de la fila administrador/web serializa los cambios y protege al último administrador activo. Al desactivar se incrementa auth_version y se revocan todos los tokens; EnsureActiveUser comprueba estado y versión de sesión en cada petición. No sincronizar roles ni cambiar active desde controladores o vistas fuera de este caso de uso.

## Asignaciones de rutas y bloqueos compatibles
Las escrituras de rutas pasan por Application/Rutas/GestionarRutas y comparten UsuarioRepository::underAdminLock con la gestión de usuarios; no cambiar ese protocolo aisladamente. Productores se bloquean antes de comprobar disponibilidad y pertenencia. Desactivar una ruta o persona no libera asignaciones. Las pruebas de concurrencia MySQL crean bases aleatorias exclusivas; nunca reutilizar la base local. Las cardinalidades provisionales están en docs/rutas-acopio.md.
