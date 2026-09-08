<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Iniciar sesión · LactoColus</title>@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body class="min-h-screen bg-base-200 flex items-center justify-center p-4">
<main class="w-full max-w-md"><x-card title="LactoColus" subtitle="Ingresa a la administración" shadow>
<form method="POST" action="{{ route('login.store') }}" class="space-y-4">@csrf
<x-input label="Correo electrónico" name="email" type="email" :value="old('email')" required autocomplete="username" />
@error('email')<p class="text-error text-sm">{{ $message }}</p>@enderror
<x-input label="Contraseña" name="password" type="password" required autocomplete="current-password" />
@error('password')<p class="text-error text-sm">{{ $message }}</p>@enderror
<x-button label="Iniciar sesión" type="submit" class="btn-primary w-full" />
</form></x-card></main></body></html>
