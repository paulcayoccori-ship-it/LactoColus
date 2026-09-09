<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' - '.config('app.name') : config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen font-sans antialiased bg-base-200">

    {{-- NAVBAR mobile only --}}
    <x-nav sticky class="lg:hidden">
        <x-slot:brand>
            <x-app-brand />
        </x-slot:brand>
        <x-slot:actions>
            <label for="main-drawer" class="lg:hidden me-3" aria-label="Abrir menú" tabindex="0" @keydown.enter="document.getElementById('main-drawer').click()">
                <x-icon name="o-bars-3" class="cursor-pointer" />
            </label>
        </x-slot:actions>
    </x-nav>

    {{-- MAIN --}}
    <x-main>
        {{-- SIDEBAR --}}
        <x-slot:sidebar drawer="main-drawer" collapsible class="bg-base-100 lg:bg-inherit">

            {{-- BRAND --}}
            <x-app-brand class="px-5 pt-4" />

            {{-- MENU --}}
            <x-menu activate-by-route>

                {{-- User --}}
                @if($user = auth()->user())
                    <x-menu-separator />

                    <x-list-item :item="$user" value="name" sub-value="email" no-separator no-hover class="-mx-2 !-my-2 rounded">
                        <x-slot:actions>
                            <form method="POST" action="{{ route('logout') }}">@csrf<x-button icon="o-power" class="btn-circle btn-ghost btn-xs" tooltip-left="Cerrar sesión" type="submit" /></form>
                        </x-slot:actions>
                    </x-list-item>

                    <p class="px-3 py-2 text-sm">{{ $user->getRoleNames()->implode(', ') }}</p>
                    <x-menu-separator />
                @endif

                @can('ver-dashboard')<x-menu-item title="Dashboard" icon="o-home" :link="route('admin.dashboard')" />@endcan
                @can('administrar-productores')<x-menu-item title="Productores" icon="o-user-group" :link="route('admin.productores')" />@endcan
                @can('administrar-rutas')<x-menu-item title="Rutas de acopio" icon="o-map" :link="route('admin.rutas')" />@endcan
                @can('administrar-usuarios')<x-menu-item title="Usuarios" icon="o-users" :link="route('admin.usuarios')" />@endcan
                

            </x-menu>
        </x-slot:sidebar>

        {{-- The `$slot` goes here --}}
        <x-slot:content>
            {{ $slot }}
        </x-slot:content>
    </x-main>

    {{--  TOAST area --}}
    <x-toast />
</body>
</html>
