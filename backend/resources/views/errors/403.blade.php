<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Acceso denegado · LactoColus</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body class="min-h-screen bg-base-200 flex items-center justify-center p-4">
<main class="max-w-lg"><x-card title="Acceso denegado" subtitle="LactoColus" shadow>
<p>Tu cuenta no tiene acceso a este panel. Consulta con un administrador.</p>
@auth<form method="POST" action="{{ route('logout') }}" class="mt-5">@csrf<x-button label="Cerrar sesión" type="submit" class="btn-primary" /></form>@endauth
</x-card></main></body></html>
