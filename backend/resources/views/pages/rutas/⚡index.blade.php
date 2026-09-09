<?php

declare(strict_types=1);

use App\Application\Rutas\{ConsultarRutas, GestionarRutas};
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new class extends Component {
    use WithPagination, Toast;

    public string $search = '';
    public string $estado = '';
    public bool $modal = false;
    #[Locked] public ?int $rutaId = null;
    #[Locked] public ?int $detalleId = null;
    public array $form = ['codigo' => '', 'nombre' => '', 'descripcion' => '', 'estado' => true];
    public string $recolectorId = '';
    public string $productorId = '';

    public function boot(): void { Gate::authorize('administrar-rutas'); }
    public function paginationView(): string { return 'pagination'; }
    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedEstado(): void { $this->resetPage(); }

    public function create(): void {
        Gate::authorize('administrar-rutas');
        $this->reset('form', 'rutaId');
        $this->resetValidation();
        $this->modal = true;
    }
    public function open(int $id): void {
        Gate::authorize('administrar-rutas');
        $data = app(ConsultarRutas::class)->find($id);
        $this->form = array_intersect_key($data, $this->form);
        $this->rutaId = $id;
        $this->resetValidation();
        $this->modal = true;
    }
    public function show(int $id): void {
        Gate::authorize('administrar-rutas');
        app(ConsultarRutas::class)->find($id);
        $this->detalleId = $id;
        $this->reset('recolectorId', 'productorId');
        $this->resetValidation();
    }
    public function closeDetail(): void { Gate::authorize('administrar-rutas'); $this->detalleId = null; $this->resetValidation(); }
    public function save(): void {
        $this->perform(function (): void {
            $id = app(GestionarRutas::class)->save($this->rutaId, $this->form);
            $this->modal = false;
            $this->show($id);
            $this->resetPage();
        });
    }
    public function changeState(int $id, bool $estado): void {
        $this->perform(fn () => app(GestionarRutas::class)->changeState($id, $estado));
    }
    public function assignCollector(): void {
        $this->perform(function (): void { app(GestionarRutas::class)->collector($this->selected(), $this->recolectorId); $this->recolectorId = ''; });
    }
    public function removeCollector(): void {
        $this->perform(fn () => app(GestionarRutas::class)->collector($this->selected(), null));
    }
    public function addProducer(): void {
        $this->perform(function (): void { app(GestionarRutas::class)->attach($this->selected(), $this->productorId); $this->productorId = ''; });
    }
    public function removeProducer(int $id): void {
        $this->perform(fn () => app(GestionarRutas::class)->detach($this->selected(), $id));
    }
    public function moveProducer(int $id, int $direction): void {
        $this->perform(fn () => app(GestionarRutas::class)->move($this->selected(), $id, $direction));
    }
    private function selected(): int { abort_if($this->detalleId === null, 404); return $this->detalleId; }
    private function perform(Closure $operation): void {
        Gate::authorize('administrar-rutas');
        $this->resetValidation();
        try {
            $operation();
            $this->success('Cambios guardados correctamente.');
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) { $this->addError('form.'.$field, $messages[0]); }
            $this->error(collect($exception->errors())->flatten()->first());
        } catch (\Illuminate\Database\QueryException $exception) {
            report($exception);
            $this->addError('form.conflicto', 'No se pudo guardar. Intenta nuevamente.');
            $this->error('No se pudo guardar. Intenta nuevamente.');
        }
    }
    public function with(): array {
        return app(ConsultarRutas::class)->handle($this->search, $this->estado, $this->detalleId) + [
            'headers' => [['key' => 'codigo', 'label' => 'Código'], ['key' => 'nombre', 'label' => 'Nombre'], ['key' => 'estado', 'label' => 'Estado'], ['key' => 'recolector', 'label' => 'Recolector responsable'], ['key' => 'productores_count', 'label' => 'Productores']],
        ];
    }
}; ?>

<div class="space-y-6">
    <x-header title="Rutas de acopio" subtitle="Organiza responsables, productores y orden de visita" separator progress-indicator>
        <x-slot:actions><x-button label="Nueva ruta" icon="o-plus" wire:click="create" class="btn-primary" /></x-slot:actions>
    </x-header>
    @if($errors->any())
        <div class="alert alert-error" role="alert"><ul>@foreach($errors->all() as $message)<li wire:key="error-{{ $loop->index }}">{{ $message }}</li>@endforeach</ul></div>
    @endif
    <x-card shadow>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <x-input label="Buscar rutas" placeholder="Código o nombre" wire:model.live.debounce.300ms="search" icon="o-magnifying-glass" />
            <x-select label="Estado" :options="[['id' => '1', 'name' => 'Activa'], ['id' => '0', 'name' => 'Inactiva']]" wire:model.live="estado" placeholder="Todos los estados" placeholder-value="" />
        </div>
        <div class="overflow-x-auto mt-5">
            <x-table :headers="$headers" :rows="$rutas" with-pagination>
                @scope('cell_estado', $ruta)<span class="badge {{ $ruta['estado'] ? 'badge-success' : 'badge-neutral' }}">{{ $ruta['estado'] ? 'Activa' : 'Inactiva' }}</span>@endscope
                @scope('cell_recolector', $ruta)
                    {{ $ruta['recolector'] ?? 'Sin recolector asignado' }}
                    @if($ruta['recolector_id'] && !$ruta['recolector_disponible'])<p class="text-warning">Advertencia: inactivo o sin rol recolector.</p>@endif
                @endscope
                @scope('actions', $ruta)
                    <div class="flex gap-2">
                        <x-button label="Consultar" wire:click="show({{ $ruta['id'] }})" class="btn-ghost btn-sm" />
                        <x-button icon="o-pencil-square" aria-label="Editar ruta" wire:click="open({{ $ruta['id'] }})" class="btn-ghost btn-sm" />
                        <x-button :label="$ruta['estado'] ? 'Desactivar' : 'Activar'" wire:click="changeState({{ $ruta['id'] }}, {{ $ruta['estado'] ? 'false' : 'true' }})" wire:confirm="¿Confirmas el cambio de estado? Las asignaciones se conservarán." class="btn-ghost btn-sm" spinner />
                    </div>
                @endscope
                <x-slot:empty><div class="p-8 text-center">No se encontraron rutas. Crea una nueva ruta o ajusta los filtros.</div></x-slot:empty>
            </x-table>
        </div>
    </x-card>
    @if($detalle)
        <x-card :title="$detalle['codigo'].' — '.$detalle['nombre']" shadow>
            <div class="space-y-5" wire:key="detalle-{{ $detalle['id'] }}">
                <div class="flex flex-wrap justify-between gap-3"><span class="badge">{{ $detalle['estado'] ? 'Activa' : 'Inactiva · conserva sus asignaciones' }}</span><x-button label="Cerrar detalle" wire:click="closeDetail" class="btn-ghost btn-sm" /></div>
                <p class="whitespace-pre-line">{{ $detalle['descripcion'] ?: 'Sin descripción.' }}</p>
                <h2 class="font-semibold">Recolector responsable</h2>
                <p>{{ $detalle['recolector'] ?? 'Sin recolector asignado' }}</p>
                @if($detalle['recolector_id'] && !$detalle['recolector_disponible'])<div class="alert alert-warning">El responsable está inactivo o perdió el rol recolector. La asignación se conserva.</div>@endif
                @if($recolectores)
                    <x-form wire:submit="assignCollector">
                        <x-select label="Asignar o reemplazar responsable" :options="$recolectores" wire:model.live="recolectorId" placeholder="Selecciona un recolector activo" placeholder-value="" />
                        <x-slot:actions><x-button label="Guardar responsable" type="submit" class="btn-primary" spinner="assignCollector" :disabled="$recolectorId === ''" /></x-slot:actions>
                    </x-form>
                @else
                    <p>No hay recolectores activos disponibles. Revisa el estado y los roles en Usuarios.</p>
                @endif
                @if($detalle['recolector_id'])<x-button label="Retirar responsable" wire:click="removeCollector" wire:confirm="¿Retirar al responsable de esta ruta?" class="btn-outline" spinner />@endif
                <h2 class="font-semibold">Productores y orden de visita ({{ $detalle['productores_count'] }})</h2>
                @if($disponibles)
                    <x-form wire:submit="addProducer">
                        <x-select label="Incorporar productor al final de la ruta" :options="$disponibles" wire:model="productorId" placeholder="Selecciona un productor activo sin ruta" placeholder-value="" />
                        <x-slot:actions><x-button label="Incorporar productor" type="submit" class="btn-primary" spinner="addProducer" /></x-slot:actions>
                    </x-form>
                @else
                    <p>No hay productores activos sin ruta disponibles. Revisa Productores o retira primero la asignación en su ruta actual.</p>
                @endif
                <p class="text-sm">Cada productor solo puede pertenecer a una ruta. Usa Subir y Bajar para definir el orden de visita.</p>
                <div class="overflow-x-auto"><table class="table"><thead><tr><th>Orden</th><th>Productor</th><th>Acciones</th></tr></thead><tbody>
                    @forelse($detalle['productores'] as $productor)
                        <tr wire:key="asignado-{{ $detalle['id'] }}-{{ $productor['id'] }}">
                            <td>{{ $productor['orden'] }}</td><td>{{ $productor['nombre'] }}@if(!$productor['disponible'])<p class="text-warning">Advertencia: productor inactivo o eliminado. Asignación conservada.</p>@endif</td>
                            <td><div class="flex gap-2">
                                <x-button label="Subir" wire:click="moveProducer({{ $productor['id'] }}, -1)" :disabled="$loop->first" class="btn-ghost btn-sm" spinner />
                                <x-button label="Bajar" wire:click="moveProducer({{ $productor['id'] }}, 1)" :disabled="$loop->last" class="btn-ghost btn-sm" spinner />
                                <x-button label="Retirar" wire:click="removeProducer({{ $productor['id'] }})" wire:confirm="¿Retirar al productor de esta ruta?" class="btn-ghost btn-sm" spinner />
                            </div></td>
                        </tr>
                    @empty
                        <tr><td colspan="3">Esta ruta aún no tiene productores asignados.</td></tr>
                    @endforelse
                </tbody></table></div>
            </div>
        </x-card>
    @endif
    <x-modal wire:model="modal" :title="$rutaId ? 'Editar ruta' : 'Nueva ruta'" box-class="max-w-2xl">
        <x-form wire:submit="save">
            <x-input label="Código *" wire:model="form.codigo" maxlength="50" />
            <x-input label="Nombre *" wire:model="form.nombre" maxlength="150" />
            <x-textarea label="Descripción" wire:model="form.descripcion" maxlength="5000" />
            <x-checkbox label="Ruta activa" wire:model="form.estado" />
            @error('form.conflicto')<p class="text-error" role="alert">{{ $message }}</p>@enderror
            <x-slot:actions><x-button label="Cancelar" @click="$wire.modal = false" /><x-button label="Guardar" type="submit" class="btn-primary" spinner="save" /></x-slot:actions>
        </x-form>
    </x-modal>
</div>
