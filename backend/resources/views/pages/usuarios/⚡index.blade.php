<?php

declare(strict_types=1);

use App\Application\Usuarios\{ConsultarUsuarios, GuardarUsuario};
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new class extends Component {
    use WithPagination, Toast;

    public string $search = '';
    public string $role = '';
    public string $active = '';
    public bool $modal = false;
    #[Locked] public ?int $usuarioId = null;
    public array $form = ['name' => '', 'email' => '', 'active' => true, 'roles' => []];

    public function boot(): void { Gate::authorize('administrar-usuarios'); }
    public function paginationView(): string { return 'pagination'; }
    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedRole(): void { $this->resetPage(); }
    public function updatedActive(): void { $this->resetPage(); }

    public function create(): void
    {
        Gate::authorize('administrar-usuarios');
        $this->reset('form', 'usuarioId');
        $this->resetValidation();
        $this->dispatch('limpiar-contrasena');
        $this->modal = true;
    }

    public function open(int $id, ConsultarUsuarios $query): void
    {
        Gate::authorize('administrar-usuarios');
        $data = $query->find($id);
        $this->form = array_intersect_key($data, $this->form);
        $this->usuarioId = $id;
        $this->resetValidation();
        $this->dispatch('limpiar-contrasena');
        $this->modal = true;
    }

    public function save(#[\SensitiveParameter] string $password = '', #[\SensitiveParameter] string $password_confirmation = ''): void
    {
        Gate::authorize('administrar-usuarios');
        try {
            app(GuardarUsuario::class)->handle(auth()->id(), $this->usuarioId, array_replace($this->form, ['password' => $password, 'password_confirmation' => $password_confirmation]));
            $this->modal = false;
            $this->dispatch('limpiar-contrasena');
            $this->resetPage();
            $this->success('Usuario guardado correctamente.');
            if (! auth()->user()->isActiveAdministrator()) {
                $this->redirectRoute('admin.dashboard');
                $this->skipRender();
            }
        } catch (ValidationException $exception) {
            $this->showErrors($exception);
        }
    }

    public function changeState(int $id, bool $active, GuardarUsuario $save): void
    {
        Gate::authorize('administrar-usuarios');
        try {
            $save->changeState(auth()->id(), $id, $active);
            $this->success($active ? 'Usuario activado.' : 'Usuario desactivado.');
        } catch (ValidationException $exception) {
            $this->showErrors($exception);
        }
    }

    private function showErrors(ValidationException $exception): void
    {
        $this->resetValidation();
        foreach ($exception->errors() as $field => $messages) {
            $this->addError('form.'.$field, $messages[0]);
        }
        $this->error(collect($exception->errors())->flatten()->first());
    }

    public function with(): array
    {
        return app(ConsultarUsuarios::class)->handle($this->search, $this->role, $this->active) + [
            'headers' => [['key' => 'name', 'label' => 'Nombre'], ['key' => 'email', 'label' => 'Correo electrónico'], ['key' => 'roles', 'label' => 'Roles'], ['key' => 'active', 'label' => 'Estado']],
        ];
    }
}; ?>

<div>
    <x-header title="Usuarios" subtitle="Administra las cuentas y sus roles" separator progress-indicator>
        <x-slot:actions><x-button label="Nuevo usuario" icon="o-plus" wire:click="create" class="btn-primary" /></x-slot:actions>
    </x-header>
    <x-card shadow>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <x-input label="Buscar usuarios" placeholder="Nombre o correo electrónico" wire:model.live.debounce.300ms="search" icon="o-magnifying-glass" />
            <x-select label="Rol" :options="$roles" option-value="name" wire:model.live="role" placeholder="Todos los roles" placeholder-value="" />
            <x-select label="Estado" :options="[['id' => '1', 'name' => 'Activo'], ['id' => '0', 'name' => 'Inactivo']]" wire:model.live="active" placeholder="Todos los estados" placeholder-value="" />
        </div>
        <div class="overflow-x-auto mt-5">
            <x-table :headers="$headers" :rows="$usuarios" with-pagination>
                @scope('cell_roles', $usuario){{ implode(', ', $usuario['roles']) }}@endscope
                @scope('cell_active', $usuario)
                    <span class="badge {{ $usuario['active'] ? 'badge-success' : 'badge-neutral' }}">{{ $usuario['active'] ? 'Activo' : 'Inactivo' }}</span>
                @endscope
                @scope('actions', $usuario)
                    <div class="flex gap-2">
                        <x-button icon="o-pencil-square" aria-label="Editar usuario" wire:click="open({{ $usuario['id'] }})" class="btn-ghost btn-sm" />
                        @if($usuario['id'] !== auth()->id())
                            <x-button :label="$usuario['active'] ? 'Desactivar' : 'Activar'" wire:click="changeState({{ $usuario['id'] }}, {{ $usuario['active'] ? 'false' : 'true' }})" wire:confirm="¿Confirmas el cambio de estado de este usuario?" class="btn-ghost btn-sm" spinner />
                        @endif
                    </div>
                @endscope
                <x-slot:empty><div class="p-8 text-center">No se encontraron usuarios.</div></x-slot:empty>
            </x-table>
        </div>
    </x-card>
    <x-modal wire:model="modal" :title="$usuarioId ? 'Editar usuario' : 'Nuevo usuario'" box-class="max-w-2xl">
        <div x-data x-on:limpiar-contrasena.window="$refs.password.value = ''; $refs.confirmation.value = ''">
            <x-form wire:submit="save($refs.password.value, $refs.confirmation.value)">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-input label="Nombre *" wire:model="form.name" maxlength="255" autocomplete="name" />
                    <x-input label="Correo electrónico *" type="email" wire:model="form.email" maxlength="255" autocomplete="email" />
                    <x-input label="Contraseña" type="password" wire:ref="password" x-ref="password" autocomplete="new-password" error-field="form.password" hint="Mínimo 8 caracteres. Al editar, deja vacío para conservarla." />
                    <x-input label="Confirmar contraseña" type="password" wire:ref="confirmation" x-ref="confirmation" autocomplete="new-password" />
                    <x-checkbox label="Usuario activo" wire:model="form.active" :disabled="$usuarioId === auth()->id()" />
                </div>
                <fieldset class="mt-4 space-y-2"><legend class="font-semibold mb-2">Roles *</legend>
                    @foreach($roles as $availableRole)
                        <label class="flex items-center gap-2" wire:key="rol-{{ $availableRole['name'] }}">
                            <input type="checkbox" class="checkbox checkbox-primary" wire:model="form.roles" value="{{ $availableRole['name'] }}" />
                            {{ $availableRole['name'] }}
                        </label>
                    @endforeach
                    @error('form.roles')<p class="text-error text-sm">{{ $message }}</p>@enderror
                    @error('form.roles.*')<p class="text-error text-sm">{{ $message }}</p>@enderror
                </fieldset>
                <x-slot:actions>
                    <x-button label="Cancelar" @click="$wire.modal = false; $refs.password.value = ''; $refs.confirmation.value = ''" />
                    <x-button label="Guardar" type="submit" class="btn-primary" spinner="save" />
                </x-slot:actions>
            </x-form>
        </div>
    </x-modal>
</div>
