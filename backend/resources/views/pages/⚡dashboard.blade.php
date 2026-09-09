<?php

declare(strict_types=1);

use App\Application\Dashboard\ConsultarDashboard;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component {
    public function boot(): void { Gate::authorize('ver-dashboard'); }

    public function with(): array
    {
        return app(ConsultarDashboard::class)->handle();
    }
}; ?>

<div class="space-y-6">
    <x-header title="Dashboard" subtitle="Resumen del padrón de LactoColus" separator />
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-10 gap-4">
        <x-stat title="Total de productores" :value="$total" icon="o-user-group" />
        <x-stat title="Productores activos" :value="$activos" icon="o-check-circle" />
        <x-stat title="Productores inactivos" :value="$inactivos" icon="o-pause-circle" />
        <x-stat title="Rutas activas" :value="$rutas_activas" icon="o-map" />
        <x-stat title="Jornadas abiertas" :value="$jornadas_abiertas" icon="o-play" />
        <x-stat title="Litros acopiados hoy" :value="$litros_hoy" icon="o-beaker" />
        <x-stat title="Recepciones hoy" :value="$recepciones_hoy" icon="o-building-office-2" />
        <x-stat title="Litros recibidos hoy" :value="$litros_recibidos_hoy" icon="o-scale" />
        <x-stat title="Alertas pendientes" :value="$alertas_pendientes" icon="o-exclamation-triangle" />
        <x-stat title="Total de usuarios" :value="$usuarios" icon="o-users" />
    </div>
    <div class="flex flex-wrap gap-3">
        @can('administrar-productores')<x-button label="Gestionar productores" :link="route('admin.productores')" icon="o-user-group" class="btn-primary" />@endcan
        @can('administrar-usuarios')<x-button label="Gestionar usuarios" :link="route('admin.usuarios')" icon="o-users" class="btn-outline" />@endcan
    </div>
    <x-card title="Últimos productores registrados" subtitle="Los cinco registros más recientes" shadow>
        <div class="overflow-x-auto">
            <table class="table"><thead><tr><th>Código</th><th>Productor</th><th>Estado</th><th>Registro</th></tr></thead>
                <tbody>
                @forelse($recientes as $productor)
                    <tr wire:key="reciente-{{ $productor['id'] }}">
                        <td>{{ $productor['codigo'] }}</td><td>{{ $productor['nombres'] }} {{ $productor['apellidos'] }}</td>
                        <td><span class="badge {{ $productor['estado'] ? 'badge-success' : 'badge-neutral' }}">{{ $productor['estado'] ? 'Activo' : 'Inactivo' }}</span></td>
                        <td>{{ \Illuminate\Support\Carbon::parse($productor['created_at'])->format('d/m/Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center py-8">Aún no hay productores registrados.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
