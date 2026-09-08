---
paths:
  - 'resources/views/pages/**'
---

# Pages

## Referencias DOM en expresiones Livewire 4
En wire:submit y otras expresiones Livewire 4, $refs resuelve wire:ref, no x-ref de Alpine. Si el mismo input se usa desde Alpine y Livewire, mantener ambas referencias. Una referencia ausente puede fallar antes de emitir la petición y dejar el formulario deshabilitado; cubrir las referencias en el HTML renderizado además de probar la acción PHP.
