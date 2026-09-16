<?php
use App\Application\Calidad\ConsultarCalidad;
use App\Application\Calidad\GestionarCalidad;
use App\Domain\Calidad\ParametrosCalidad;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;
new class extends Component {
    use WithPagination, Toast;
    public array $form = [];
    public function boot(): void { Gate::authorize('administrar-calidad'); }
    public function mount(): void { $this->blank(); }
    public function blank(): void {
        Gate::authorize('administrar-calidad'); $this->resetValidation();
        $this->form = ['nombre'=>'','activo'=>false,'vigente_desde'=>today()->toDateString(),'vigente_hasta'=>'','criterios'=>[]];
        foreach (ParametrosCalidad::criteria() as $key=>$field) { $this->form['criterios'][$key] = ['minimo'=>'','maximo'=>'','unidad'=>$field[1],'activo'=>false,'desde'=>today()->toDateString(),'hasta'=>'']; }
    }
    public function copy(int $id): void { Gate::authorize('administrar-calidad'); $this->form = app(ConsultarCalidad::class)->profile(auth()->id(),$id); $this->resetValidation(); }
    public function save(): void {
        Gate::authorize('administrar-calidad'); $this->resetValidation();
        try { $profile = app(GestionarCalidad::class)->saveProfile(auth()->id(),$this->form); $this->success('Versión '.$profile->version.' guardada.'); $this->resetPage(); }
        catch (ValidationException $e) { foreach ($e->errors() as $key=>$messages) { $this->addError($key,$messages[0]); } }
        catch (\Illuminate\Auth\Access\AuthorizationException $e) { throw $e; }
        catch (Throwable $e) { report($e); $this->addError('operacion','No se pudo guardar. Puedes volver a intentarlo.'); }
    }
    public function with(): array { return ['profiles'=>app(ConsultarCalidad::class)->profiles(auth()->id()),'fields'=>ParametrosCalidad::criteria()]; }
}; ?>
<div class="space-y-6">
    <x-header title="Perfiles y rangos de calidad" subtitle="Cada guardado crea una versión; no altera los análisis anteriores" separator><x-slot:actions><x-button label="Volver a análisis" :link="route('admin.calidad')" /></x-slot:actions></x-header>
    <x-card title="Versiones" shadow>
        <p>Se aplica la versión más reciente cuya vigencia cubra la fecha de muestra. Una versión inactiva suspende la evaluación en su período. No hay rangos predeterminados.</p>
        @forelse($profiles as $profile)<div class="flex flex-wrap gap-3 py-3" wire:key="perfil-{{ $profile->id }}"><span>Versión {{ $profile->version }} · {{ $profile->nombre }} · {{ $profile->activo ? 'Activo' : 'Inactivo' }} · {{ $profile->vigente_desde }} / {{ $profile->vigente_hasta ?? 'Sin fin' }}</span><x-button label="Consultar / usar como base" wire:click="copy({{ $profile->id }})" class="btn-sm" /></div>@empty<p>No hay perfiles. Completa rangos validados por el responsable técnico.</p>@endforelse
        {{ $profiles->links() }}
    </x-card>
    <x-card title="Nueva versión" shadow>
        @if($errors->any())<div class="alert alert-error" role="alert"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
        <x-form wire:submit="save">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3"><x-input label="Nombre" wire:model="form.nombre" /><x-checkbox label="Perfil activo" wire:model="form.activo" /><x-input label="Vigente desde" type="date" wire:model="form.vigente_desde" /><x-input label="Vigente hasta (opcional)" type="date" wire:model="form.vigente_hasta" /></div>
            @foreach($fields as $key=>$field)
                <fieldset class="border border-base-300 rounded-box p-4" wire:key="criterio-{{ $key }}"><legend>{{ $field[0] }}</legend><div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <x-input label="Mínimo" type="number" step="0.0001" wire:model="form.criterios.{{ $key }}.minimo" /><x-input label="Máximo" type="number" step="0.0001" wire:model="form.criterios.{{ $key }}.maximo" /><x-input label="Unidad" wire:model="form.criterios.{{ $key }}.unidad" readonly />
                    <x-checkbox label="Criterio activo" wire:model="form.criterios.{{ $key }}.activo" /><x-input label="Desde" type="date" wire:model="form.criterios.{{ $key }}.desde" /><x-input label="Hasta (opcional)" type="date" wire:model="form.criterios.{{ $key }}.hasta" />
                </div></fieldset>
            @endforeach
            <p>Un criterio sin mínimo, máximo o vigencia aplicable deja el análisis pendiente de revisión.</p>
            <x-slot:actions><x-button label="Limpiar" wire:click="blank" /><x-button label="Guardar nueva versión" type="submit" spinner="save" class="btn-primary" /></x-slot:actions>
        </x-form>
    </x-card>
</div>
