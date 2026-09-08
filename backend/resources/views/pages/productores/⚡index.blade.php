<?php

declare(strict_types=1);

use App\Application\Productores\{ProductorData, ProductorValidation, ListarProductores, CrearProductor, ConsultarProductor, ActualizarProductor, EliminarProductor};
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new class extends Component {
    use WithPagination, Toast;

    public string $search = '';
    public bool $modal = false;
    #[Locked] public ?int $productorId = null;
    #[Locked] public bool $readOnly = false;
    public array $form = ['codigo'=>'','dni'=>'','nombres'=>'','apellidos'=>'','celular'=>null,'email'=>null,'direccion'=>null,'comunidad'=>null,'estado'=>true];

    public function boot(): void { Gate::authorize('administrar-productores'); }
    public function paginationView(): string { return 'pagination'; }
    public function updatedSearch(): void { $this->resetPage(); }
    public function create(): void {
        Gate::authorize('administrar-productores');
        $this->reset('form','productorId','readOnly'); $this->resetValidation(); $this->modal = true;
    }
    public function open(int $id, bool $readOnly, ConsultarProductor $useCase): void {
        Gate::authorize('administrar-productores');
        $this->form = ProductorData::fromArray($useCase->handle($id))->attributes;
        $this->productorId = $id; $this->readOnly = $readOnly; $this->resetValidation(); $this->modal = true;
    }
    public function save(CrearProductor $create, ActualizarProductor $update): void {
        Gate::authorize('administrar-productores');
        abort_if($this->readOnly, 403);
        $this->form = array_map(fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $this->form);
        try {
            $data = ProductorData::fromArray($this->form);
            if ($this->productorId) { $update->handle($this->productorId, $data); }
            else { $create->handle($data); }
            $this->modal = false; $this->resetPage(); $this->success('Productor guardado correctamente.');
        } catch (ValidationException $exception) {
            $this->resetValidation();
            foreach ($exception->errors() as $field => $messages) { $this->addError('form.'.$field, $messages[0]); }
            $this->error('Revisa los datos del formulario.');
        } catch (\Throwable $exception) {
            report($exception); $this->error('No se pudo guardar el productor. Inténtalo de nuevo.');
        }
    }
    public function delete(int $id, EliminarProductor $useCase): void {
        Gate::authorize('administrar-productores');
        try { $useCase->handle($id); $this->resetPage(); $this->success('Productor eliminado correctamente.'); }
        catch (\Throwable $exception) { report($exception); $this->error('No se pudo eliminar el productor. Actualiza el listado.'); }
    }
    public function with(): array {
        return ['productores' => app(ListarProductores::class)->handle(mb_substr($this->search, 0, 150)),
            'headers' => [['key'=>'codigo','label'=>'Código'],['key'=>'dni','label'=>'DNI'],['key'=>'nombres','label'=>'Nombres'],['key'=>'apellidos','label'=>'Apellidos'],['key'=>'comunidad','label'=>'Comunidad'],['key'=>'estado','label'=>'Estado']]];
    }
}; ?>

<div>
    <x-header title="Productores" subtitle="Gestiona el padrón de productores de LactoColus" separator progress-indicator>
        <x-slot:actions><x-button label="Nuevo productor" icon="o-plus" wire:click="create" class="btn-primary" /></x-slot:actions>
    </x-header>
    <x-card shadow>
        <x-input label="Buscar productores" placeholder="Código, DNI, nombre, apellido o comunidad" wire:model.live.debounce.300ms="search" icon="o-magnifying-glass" clearable />
        <div class="overflow-x-auto mt-5">
            <x-table :headers="$headers" :rows="$productores" with-pagination>
                @scope('cell_estado', $productor)
                    <span class="badge {{ $productor['estado'] ? 'badge-success' : 'badge-neutral' }}">{{ $productor['estado'] ? 'Activo' : 'Inactivo' }}</span>
                @endscope
                @scope('actions', $productor)
                    <div class="flex gap-1">
                        <x-button icon="o-eye" aria-label="Visualizar productor" wire:click="open({{ $productor['id'] }}, true)" class="btn-ghost btn-sm" />
                        <x-button icon="o-pencil-square" aria-label="Editar productor" wire:click="open({{ $productor['id'] }}, false)" class="btn-ghost btn-sm" />
                        <x-button icon="o-trash" aria-label="Eliminar productor" wire:click="delete({{ $productor['id'] }})" wire:confirm="¿Confirmas que deseas eliminar este productor?" class="btn-ghost btn-sm text-error" spinner />
                    </div>
                @endscope
                <x-slot:empty><div class="p-8 text-center">No se encontraron productores.</div></x-slot:empty>
            </x-table>
        </div>
    </x-card>
    <x-modal wire:model="modal" :title="$readOnly ? 'Detalle del productor' : ($productorId ? 'Editar productor' : 'Nuevo productor')" class="backdrop-blur" box-class="max-w-3xl">
        <x-form wire:submit="save">
            <fieldset @disabled($readOnly) class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-input label="Código *" wire:model="form.codigo" maxlength="50" />
                <x-input label="DNI *" wire:model="form.dni" maxlength="8" inputmode="numeric" />
                <x-input label="Nombres *" wire:model="form.nombres" maxlength="150" />
                <x-input label="Apellidos *" wire:model="form.apellidos" maxlength="150" />
                <x-input label="Celular" wire:model="form.celular" maxlength="9" inputmode="numeric" />
                <x-input label="Correo electrónico" wire:model="form.email" type="email" />
                <x-input label="Dirección" wire:model="form.direccion" maxlength="255" />
                <x-input label="Comunidad" wire:model="form.comunidad" maxlength="150" />
                <x-checkbox label="Activo" wire:model="form.estado" />
            </fieldset>
            <x-slot:actions>
                <x-button label="Cerrar" @click="$wire.modal = false" />
                @unless($readOnly)<x-button label="Guardar" type="submit" class="btn-primary" spinner="save" />@endunless
            </x-slot:actions>
        </x-form>
    </x-modal>
</div>
