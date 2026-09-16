<?php
use App\Application\Calidad\ConsultarCalidad;
use App\Application\Calidad\GestionarCalidad;
use App\Domain\Calidad\ParametrosCalidad;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new class extends Component {
    use WithPagination, Toast;
    public array $filters = ['desde'=>'','hasta'=>'','productor_id'=>'','ruta_id'=>'','responsable_id'=>'','estado'=>'','agua'=>''];
    public array $form = [];
    public bool $modal = false;
    #[Locked] public ?string $detailUuid = null;
    #[Locked] public ?int $editingId = null;
    public string $motivo = '';

    public function boot(): void { Gate::authorize('operar-calidad'); }
    public function updatedFilters(): void { Gate::authorize('operar-calidad'); $this->resetPage(); }
    public function create(): void {
        Gate::authorize('operar-calidad');
        $this->resetValidation(); $this->editingId = null; $this->motivo = '';
        $this->form = array_fill_keys(array_keys(ParametrosCalidad::CAMPOS), '') + ['uuid_externo'=>(string) Str::uuid(),'productor_id'=>'','jornada_id'=>'','entrega_id'=>'','muestra_at'=>now()->format('Y-m-d\TH:i'),'equipo'=>'','fuente'=>'manual','observaciones'=>''];
        $this->modal = true;
    }
    public function show(string $uuid): void { Gate::authorize('operar-calidad'); app(ConsultarCalidad::class)->find(auth()->id(), $uuid); $this->detailUuid = $uuid; $this->motivo = ''; $this->resetValidation(); }
    public function edit(): void {
        Gate::authorize('administrar-calidad');
        $record = app(ConsultarCalidad::class)->find(auth()->id(), $this->detailUuid);
        $this->editingId = $record->id;
        $this->form = $record->only(array_keys(ParametrosCalidad::rules(false)));
        $this->form['muestra_at'] = $record->muestra_at->format('Y-m-d\TH:i');
        $this->motivo = ''; $this->resetValidation(); $this->modal = true;
    }
    public function save(): void {
        Gate::authorize($this->editingId ? 'administrar-calidad' : 'operar-calidad');
        $this->perform(function (): void {
            $service = app(GestionarCalidad::class);
            if ($this->editingId) { $record = $service->correct(auth()->id(),$this->editingId,$this->form,$this->motivo); }
            else { $record = $service->register(auth()->id(),$this->form)['analisis']; }
            $this->detailUuid = $record->uuid_publico; $this->modal = false; $this->success('Análisis guardado.');
        });
    }
    public function review(): void {
        Gate::authorize('administrar-calidad');
        $this->perform(function (): void { $record = app(ConsultarCalidad::class)->find(auth()->id(),$this->detailUuid); app(GestionarCalidad::class)->review(auth()->id(),$record->id,$this->motivo); $this->success('Revisión registrada.'); });
    }
    public function annul(): void {
        Gate::authorize('administrar-calidad');
        $this->perform(function (): void { $record = app(ConsultarCalidad::class)->find(auth()->id(),$this->detailUuid); app(GestionarCalidad::class)->annul(auth()->id(),$record->id,$this->motivo); $this->success('Análisis anulado.'); });
    }
    private function perform(Closure $action): void {
        $this->resetValidation();
        try { $action(); }
        catch (ValidationException $e) { foreach ($e->errors() as $key=>$messages) { $this->addError($key,$messages[0]); } }
        catch (\Illuminate\Auth\Access\AuthorizationException $e) { throw $e; }
        catch (Throwable $e) { report($e); $this->addError('operacion','No se pudo completar la operación. Puedes volver a intentarlo.'); }
    }
    public function with(): array {
        $query = app(ConsultarCalidad::class);
        return ['records'=>$query->listing(auth()->id(),$this->filters),'detail'=>$this->detailUuid ? $query->find(auth()->id(),$this->detailUuid) : null,'options'=>$query->options(auth()->id(),empty($this->form['productor_id']) ? null : (int) $this->form['productor_id']),'fields'=>ParametrosCalidad::CAMPOS];
    }
}; ?>
<div class="space-y-6">
    <x-header title="Control de calidad" subtitle="Resultados Lactoscan por productor" separator>
        <x-slot:actions>@can('administrar-calidad')<x-button label="Perfiles y rangos" :link="route('admin.calidad.perfiles')" />@endcan<x-button label="Registrar análisis" wire:click="create" icon="o-plus" class="btn-primary" /></x-slot:actions>
    </x-header>
    @if($errors->any())<div class="alert alert-error" role="alert"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
    <x-card shadow>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <x-input label="Desde" type="date" wire:model.live="filters.desde" /><x-input label="Hasta" type="date" wire:model.live="filters.hasta" />
            <x-select label="Productor" wire:model.live="filters.productor_id" :options="$options['productores_filtro']" placeholder="Todos" />
            <x-select label="Ruta histórica" wire:model.live="filters.ruta_id" :options="$options['rutas']" placeholder="Todas" />
            <x-select label="Responsable" wire:model.live="filters.responsable_id" :options="$options['responsables']" placeholder="Todos" />
            <x-select label="Estado" wire:model.live="filters.estado" :options="[['id'=>'pendiente_revision','name'=>'Pendiente de revisión'],['id'=>'conforme','name'=>'Conforme'],['id'=>'observado','name'=>'Observado'],['id'=>'anulado','name'=>'Anulado']]" placeholder="Todos" />
            <x-select label="Agua añadida" wire:model.live="filters.agua" :options="[['id'=>'si','name'=>'Mayor que cero'],['id'=>'no','name'=>'Sin agua añadida']]" placeholder="Todos" />
        </div>
        <div class="overflow-x-auto mt-4"><table class="table"><thead><tr><th>Muestra</th><th>Productor / ruta</th><th>Responsable</th><th>Grasa / proteína</th><th>Densidad corregida / pH</th><th>Agua %</th><th>Estado</th><th></th></tr></thead><tbody>
        @forelse($records as $record)
            <tr wire:key="analisis-{{ $record->id }}"><td>{{ $record->muestra_at->format('d/m/Y H:i') }}</td><td>{{ $record->productor->codigo }} — {{ $record->productor->nombres }} {{ $record->productor->apellidos }}<br>{{ $record->ruta?->nombre ?? 'Sin ruta' }}</td><td>{{ $record->responsable->name }}</td><td>{{ $record->grasa }} / {{ $record->proteina }} %</td><td>{{ $record->densidad_corregida ?? 'Sin corregir' }} / {{ $record->ph }}</td><td>{{ $record->agua_anadida }}</td><td>{{ str_replace('_',' ',$record->estado) }}<br>{{ count($record->advertencias) }} advertencias</td><td><x-button label="Consultar" wire:click="show('{{ $record->uuid_publico }}')" class="btn-sm" /></td></tr>
        @empty<tr><td colspan="8" class="py-8 text-center">No hay análisis para estos filtros. Registra una muestra de un productor activo.</td></tr>@endforelse
        </tbody></table></div>{{ $records->links() }}
    </x-card>
    @if($detail)
        <x-card title="Detalle del análisis" shadow>
            <p>{{ $detail->uuid_publico }} · {{ $detail->productor->codigo }} · {{ $detail->responsable->name }}</p>
            <p>Estado: {{ str_replace('_',' ',$detail->estado) }} · Muestra: {{ $detail->muestra_at->format('d/m/Y H:i') }} · Fuente: {{ $detail->fuente }} · Equipo: {{ $detail->equipo ?? 'Sin identificar' }}</p>
            <p>Ruta: {{ $detail->ruta?->nombre ?? 'Sin ruta' }} · Jornada: {{ $detail->jornada_id ?? 'Sin vincular' }} · Entrega: {{ $detail->entrega_id ?? 'Sin vincular' }}</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 my-4">@foreach($fields as $key=>$field)<p wire:key="dato-{{ $key }}"><strong>{{ $field[0] }}:</strong> {{ $detail->{$key} ?? 'Sin registrar' }} {{ $field[1] }}</p>@endforeach</div>
            <p>{{ $detail->observaciones }}</p>
            @foreach($detail->advertencias as $key=>$warning)<p class="text-warning" wire:key="advertencia-{{ $key }}">{{ $fields[$key][0] ?? 'Perfil' }}: {{ $warning }}</p>@endforeach
            <details class="my-3"><summary>Límites aplicados (versión {{ $detail->limites_aplicados['version'] ?? 'sin perfil' }})</summary><pre class="overflow-x-auto text-xs">{{ json_encode($detail->limites_aplicados, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>
            @can('administrar-calidad')
                @if($detail->estado !== 'anulado')<x-textarea label="Motivo de revisión o anulación" wire:model="motivo" /><div class="flex flex-wrap gap-2 mt-3"><x-button label="Corregir" wire:click="edit" /><x-button label="Revisar con perfil vigente a la muestra" wire:click="review" spinner="review" /><x-button label="Anular" wire:click="annul" wire:confirm="¿Anular este análisis conservando su historial?" class="btn-error" spinner="annul" /></div>@endif
                <h3 class="font-semibold mt-4">Auditoría</h3>
                @forelse($detail->auditorias as $audit)<details wire:key="audit-{{ $audit->id }}"><summary>{{ $audit->accion }} · {{ $audit->usuario->name }} · {{ $audit->created_at }} · {{ $audit->motivo }}</summary><pre class="text-xs overflow-x-auto">Anterior: {{ json_encode($audit->anteriores, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}
Nuevo: {{ json_encode($audit->nuevos, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>@empty<p>Sin correcciones ni revisiones.</p>@endforelse
            @endcan
        </x-card>
    @endif
    <x-modal wire:model="modal" title="Análisis de calidad" class="backdrop-blur" box-class="max-w-4xl">
        @if($errors->any())<div class="alert alert-error"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
        <x-form wire:submit="save">
            @if(!$editingId)
                @if(empty($options['productores']))<p>No hay productores activos disponibles. Un administrador debe habilitar un productor.</p>@endif
                <x-select label="Productor" wire:model.live="form.productor_id" :options="$options['productores']" placeholder="Seleccionar" />
                <x-select label="Jornada opcional" wire:model="form.jornada_id" :options="$options['jornadas']" placeholder="Sin vincular" />
                <x-select label="Entrega opcional del productor" wire:model="form.entrega_id" :options="$options['entregas']" placeholder="Sin vincular" />
            @endif
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <x-input label="Fecha y hora de muestra" type="datetime-local" wire:model="form.muestra_at" /><x-input label="Identificación del equipo (opcional)" wire:model="form.equipo" />
                <x-select label="Fuente" wire:model="form.fuente" :options="[['id'=>'manual','name'=>'Manual'],['id'=>'dispositivo','name'=>'Dispositivo (lectura importada)']]" />
                @foreach($fields as $key=>$field)<x-input wire:key="campo-{{ $key }}" :label="$field[0].' ('.$field[1].')'" type="number" step="0.0001" min="0" :max="$field[2]" wire:model="form.{{ $key }}" />@endforeach
            </div>
            <p class="text-sm">Los sólidos totales son una medición opcional para ranking; no se calculan a partir de grasa u otros valores. La densidad corregida es opcional y debe provenir de una medición o cálculo validado. La corrección automática está pendiente de validación técnica.</p>
            <x-textarea label="Observaciones" wire:model="form.observaciones" />
            @if($editingId)<x-textarea label="Motivo obligatorio de corrección" wire:model="motivo" />@endif
            <x-slot:actions><x-button label="Cancelar" @click="$wire.modal = false" /><x-button label="Guardar" type="submit" spinner="save" class="btn-primary" /></x-slot:actions>
        </x-form>
    </x-modal>
</div>
