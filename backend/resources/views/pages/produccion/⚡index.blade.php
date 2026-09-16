<?php
use App\Application\Produccion\GestionarProduccion;
use App\Application\Produccion\ConsultarProduccion;
use App\Application\Operacion\ReglasOperativas;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Locked;
use Mary\Traits\Toast;
new class extends Component {
    use WithPagination, Toast;
    public array $filters=['buscar'=>'','estado'=>'','tipo'=>''];
    public array $form=[];
    public array $range=['minimo'=>'11','maximo'=>'12'];
    public string $ruleReason='';
    public string $motivo='';
    public string $moldes='0';
    public string $delta='';
    public string $adjustUuid='';
    public bool $modal=false;
    #[Locked] public ?string $detailUuid=null;
    #[Locked] public bool $editing=false;
    public function boot(): void { Gate::authorize('administrar-produccion'); }
    public function mount(): void { $this->range=app(ReglasOperativas::class)->current('rendimiento')['valores']; }
    public function updatedFilters(): void { Gate::authorize('administrar-produccion'); $this->resetPage(); }
    public function create(): void { Gate::authorize('administrar-produccion'); $this->resetValidation(); $this->editing=false; $this->form=['uuid'=>(string)Str::uuid(),'codigo'=>'','tipo'=>'paria_fresco','producido_at'=>now()->format('Y-m-d\TH:i'),'observaciones'=>'','recepciones'=>[['recepcion_id'=>'','litros'=>'']]]; $this->modal=true; }
    public function addReceipt(): void { Gate::authorize('administrar-produccion'); $this->form['recepciones'][]=['recepcion_id'=>'','litros'=>'']; }
    public function removeReceipt(int $index): void { Gate::authorize('administrar-produccion'); unset($this->form['recepciones'][$index]); $this->form['recepciones']=array_values($this->form['recepciones']); }
    public function show(string $uuid): void { Gate::authorize('administrar-produccion'); $lot=app(ConsultarProduccion::class)->find(auth()->id(),$uuid); $this->detailUuid=$uuid; $this->moldes=(string)$lot->moldes; $this->motivo=''; $this->delta=''; $this->adjustUuid=(string)Str::uuid(); $this->resetValidation(); }
    public function edit(): void { Gate::authorize('administrar-produccion'); $lot=app(ConsultarProduccion::class)->find(auth()->id(),$this->detailUuid); $this->form=$lot->only(['codigo','tipo','observaciones']); $this->form['producido_at']=$lot->producido_at->format('Y-m-d\TH:i'); $this->editing=true; $this->motivo=''; $this->modal=true; }
    public function save(): void { Gate::authorize('administrar-produccion'); $this->perform(function (): void { $service=app(GestionarProduccion::class); $lot=$this->editing?$service->correctDraft(auth()->id(),$this->detailUuid,$this->form,$this->motivo):$service->create(auth()->id(),$this->form); $this->show($lot->uuid); $this->modal=false; $this->success('Lote guardado.'); }); }
    public function runAction(string $action): void {
        Gate::authorize('administrar-produccion');
        $this->perform(function () use ($action): void {
            $service=app(GestionarProduccion::class); $actor=auth()->id();
            match ($action) {
                'iniciar'=>$service->start($actor,$this->detailUuid),
                'finalizar'=>$service->finish($actor,$this->detailUuid,$this->moldes),
                'ajustar'=>$service->adjust($actor,$this->detailUuid,['uuid'=>$this->adjustUuid,'delta_moldes'=>$this->delta,'motivo'=>$this->motivo]),
                'anular'=>$service->annul($actor,$this->detailUuid,$this->motivo),
                default=>abort(404),
            };
            if ($action==='ajustar') { $this->adjustUuid=(string)Str::uuid(); $this->delta=''; }
            $this->success('Operación registrada.');
        });
    }
    public function saveRule(): void { Gate::authorize('administrar-produccion'); $this->perform(function (): void { app(ReglasOperativas::class)->saveProduction(auth()->id(),$this->range,$this->ruleReason); $this->success('Nueva versión de rendimiento guardada.'); }); }
    private function perform(Closure $operation): void { $this->resetValidation(); try { $operation(); } catch (ValidationException $e) { foreach ($e->errors() as $key=>$messages) { $this->addError($key,$messages[0]); } } catch (\Illuminate\Auth\Access\AuthorizationException $e) { throw $e; } catch (Throwable $e) { report($e); $this->addError('operacion','No se pudo completar la operación. Puedes reintentar.'); } }
    public function with(): array { $query=app(ConsultarProduccion::class); return ['records'=>$query->listing(auth()->id(),$this->filters),'detail'=>$this->detailUuid?$query->find(auth()->id(),$this->detailUuid):null,'receipts'=>$query->options(auth()->id()),'audit'=>$this->detailUuid?$query->audit(auth()->id(),$this->detailUuid):[],'rule'=>app(ReglasOperativas::class)->current('rendimiento')]; }
}; ?>
<div class="space-y-6">
    <x-header title="Producción de queso" subtitle="Lotes de Paria y rendimiento por 100 litros" separator><x-slot:actions><x-button label="Nuevo lote" wire:click="create" class="btn-primary" icon="o-plus" /></x-slot:actions></x-header>
    @if($errors->any())<div class="alert alert-error" role="alert"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
    <x-card shadow>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3"><x-input label="Buscar código" wire:model.live.debounce="filters.buscar" /><x-select label="Estado" wire:model.live="filters.estado" :options="[['id'=>'borrador','name'=>'Borrador'],['id'=>'en_proceso','name'=>'En proceso'],['id'=>'finalizado','name'=>'Finalizado'],['id'=>'anulado','name'=>'Anulado']]" placeholder="Todos" /><x-select label="Tipo" wire:model.live="filters.tipo" :options="[['id'=>'paria_fresco','name'=>'Paria fresco'],['id'=>'paria_pasteurizado','name'=>'Paria pasteurizado']]" placeholder="Todos" /></div>
        <div class="overflow-x-auto mt-4"><table class="table"><thead><tr><th>Código / fecha</th><th>Tipo</th><th>Litros</th><th>Moldes</th><th>Rendimiento original</th><th>Estado</th><th></th></tr></thead><tbody>
        @forelse($records as $record)<tr wire:key="lote-{{ $record->id }}"><td>{{ $record->codigo }}<br>{{ $record->producido_at->format('d/m/Y H:i') }}</td><td>{{ str_replace('_',' ',$record->tipo) }}</td><td>{{ $record->litros_cuba }}</td><td>{{ $record->moldes }} (ajustes: {{ $record->ajustes_sum_delta_moldes ?? 0 }})</td><td>{{ $record->rendimiento ?? 'Pendiente' }}</td><td>{{ str_replace('_',' ',$record->estado) }} @if($record->alerta?->estado==='pendiente')<span class="badge badge-warning">Rendimiento fuera de rango</span>@endif</td><td><x-button label="Consultar" wire:click="show('{{ $record->uuid }}')" class="btn-sm" /></td></tr>@empty<tr><td colspan="7" class="text-center py-8">No hay lotes para estos filtros. Crea uno a partir de recepciones vigentes.</td></tr>@endforelse
        </tbody></table></div>{{ $records->links() }}
    </x-card>
    @if($detail)<x-card :title="'Lote '.$detail->codigo" shadow>
        <p>{{ $detail->uuid }} · {{ $detail->responsable->name }} · {{ str_replace('_',' ',$detail->estado) }}</p><p>{{ $detail->observaciones }}</p>
        <p>Litros en cuba: {{ $detail->litros_cuba }}. Moldes originales: {{ $detail->moldes }}. Ajustes: {{ $detail->ajustes_sum_delta_moldes ?? 0 }}.</p>
        <p>Rango aplicado: {{ $detail->regla_aplicada['valores']['minimo'] }}–{{ $detail->regla_aplicada['valores']['maximo'] }} moldes/100 L (versión {{ $detail->regla_aplicada['version'] }}).</p>
        @if($detail->alerta)<p class="text-warning">Alerta {{ $detail->alerta->estado }}: rendimiento efectivo {{ $detail->alerta->rendimiento }}. Esta desviación no se atribuye a ningún productor.</p>@endif
        <ul class="my-3">@foreach($detail->usos as $use)<li wire:key="uso-{{ $use->id }}">Recepción {{ $use->recepcion->uuid_publico }}: {{ $use->litros }} L · {{ $use->estado }}</li>@endforeach</ul>
        @if($detail->estado==='borrador')<div class="flex gap-2"><x-button label="Corregir datos" wire:click="edit" /><x-button label="Iniciar producción" wire:click="runAction('iniciar')" spinner="runAction" class="btn-primary" /></div>@endif
        @if($detail->estado==='en_proceso')<x-input label="Moldes producidos" type="number" min="0" step="1" wire:model="moldes" /><x-button label="Finalizar lote" wire:click="runAction('finalizar')" spinner="runAction" class="btn-primary mt-3" />@endif
        @if($detail->estado!=='anulado')
            <x-textarea label="Motivo obligatorio para ajuste o anulación" wire:model="motivo" class="mt-4" />
            @if($detail->estado==='finalizado')<x-input label="Ajuste de moldes (+ ingreso / − retiro)" type="number" step="1" wire:model="delta" /><x-button label="Registrar ajuste auditado" wire:click="runAction('ajustar')" spinner="runAction" class="mt-3" />@endif
            <x-button label="Anular lote" wire:click="runAction('anular')" wire:confirm="¿Anular el lote? Los litros ya consumidos no volverán a estar disponibles." spinner="runAction" class="btn-error mt-3" />
        @endif
        <h3 class="font-semibold mt-4">Historial</h3>@foreach($audit as $item)<details wire:key="audit-{{ $item['id'] }}"><summary>{{ $item['accion'] }} · {{ $item['usuario']['name'] }} · {{ $item['created_at'] }} · {{ $item['motivo'] }}</summary><pre class="overflow-x-auto text-xs">{{ json_encode(['anterior'=>$item['anteriores'],'nuevo'=>$item['nuevos']],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>@endforeach
    </x-card>@endif
    <x-card title="Configuración de rendimiento" shadow><p>Versión {{ $rule['version'] }}. Los lotes conservan el rango utilizado al crearlos.</p><x-form wire:submit="saveRule"><div class="grid grid-cols-1 md:grid-cols-2 gap-3"><x-input label="Mínimo (moldes/100 L)" type="number" step="0.001" wire:model="range.minimo" /><x-input label="Máximo (moldes/100 L)" type="number" step="0.001" wire:model="range.maximo" /></div><x-textarea label="Motivo de cambio" wire:model="ruleReason" /><x-slot:actions><x-button label="Guardar nueva versión" type="submit" spinner="saveRule" /></x-slot:actions></x-form></x-card>
    <x-modal wire:model="modal" title="Datos del lote" box-class="max-w-3xl">
        @if($errors->any())<div class="alert alert-error"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
        <x-form wire:submit="save"><x-input label="Código único" wire:model="form.codigo" /><x-input label="Fecha y hora" type="datetime-local" wire:model="form.producido_at" /><x-select label="Tipo de queso" wire:model="form.tipo" :options="[['id'=>'paria_fresco','name'=>'Paria fresco'],['id'=>'paria_pasteurizado','name'=>'Paria pasteurizado']]" />
            @if(!$editing)
                @if(empty($receipts))<p>No hay recepciones vigentes. Registra primero una recepción en planta.</p>@endif
                @foreach($form['recepciones']??[] as $index=>$allocation)<div class="flex flex-wrap gap-3" wire:key="recepcion-form-{{ $index }}"><x-select label="Recepción" wire:model="form.recepciones.{{ $index }}.recepcion_id" :options="$receipts" placeholder="Seleccionar" /><x-input label="Litros a utilizar" type="number" step="0.001" min="0.001" wire:model="form.recepciones.{{ $index }}.litros" /><x-button label="Retirar fila" wire:click="removeReceipt({{ $index }})" class="btn-sm" /></div>@endforeach
                <x-button label="Añadir recepción" wire:click="addReceipt" />
            @else<x-textarea label="Motivo de corrección" wire:model="motivo" />@endif
            <x-textarea label="Observaciones" wire:model="form.observaciones" /><x-slot:actions><x-button label="Cancelar" @click="$wire.modal=false" /><x-button label="Guardar" type="submit" spinner="save" class="btn-primary" /></x-slot:actions>
        </x-form>
    </x-modal>
</div>
