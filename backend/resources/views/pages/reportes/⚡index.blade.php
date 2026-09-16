<?php
use App\Application\Reportes\ConsultarReportes;
use Livewire\Component;
use Livewire\WithPagination;
new class extends Component {
 use WithPagination;
 public string $type=''; public array $filters=['desde'=>'','hasta'=>'','ruta_id'=>'','productor_id'=>'','recolector_id'=>''];
 public function boot(): void { app(ConsultarReportes::class)->options(auth()->id()); }
 public function mount(): void { $this->type=array_key_first(app(ConsultarReportes::class)->options(auth()->id())); }
 public function apply(): void { app(ConsultarReportes::class)->options(auth()->id()); $this->resetValidation(); $this->resetPage(); }
 public function updatedType(): void { app(ConsultarReportes::class)->options(auth()->id()); $this->resetPage(); $this->filters=['desde'=>'','hasta'=>'','ruta_id'=>'','productor_id'=>'','recolector_id'=>'']; }
 public function with(): array { $service=app(ConsultarReportes::class); $records=null; $this->resetValidation(); try { $records=$service->query(auth()->id(),$this->type,$this->filters)->paginate(50); } catch(\Illuminate\Validation\ValidationException $e) { foreach($e->errors() as $key=>$messages) { $this->addError($key,$messages[0]); } } return ['options'=>collect($service->options(auth()->id()))->map(fn($name,$id)=>['id'=>$id,'name'=>$name])->values()->all(),'records'=>$records]; }
}; ?>
<div class="space-y-6"><x-header title="Reportes" subtitle="Consulta y exportación de datos autorizados" separator />
<x-card shadow><x-form wire:submit="apply"><x-select label="Reporte" wire:model.live="type" :options="$options" /><div class="grid md:grid-cols-2 gap-4"><x-input label="Desde" type="date" wire:model="filters.desde" /><x-input label="Hasta" type="date" wire:model="filters.hasta" /><x-input label="ID de ruta (si corresponde)" type="number" min="1" wire:model="filters.ruta_id" /><x-input label="ID de productor (si corresponde)" type="number" min="1" wire:model="filters.productor_id" /><x-input label="ID de recolector (volumen o recepción)" type="number" min="1" wire:model="filters.recolector_id" /></div><x-slot:actions><x-button label="Aplicar filtros" type="submit" spinner="apply" class="btn-primary" /></x-slot:actions></x-form></x-card>
@if($errors->any())<div class="alert alert-error" role="alert">@foreach($errors->all() as $message)<p>{{ $message }}</p>@endforeach</div>@endif
@if($records)<x-card shadow><x-button label="Exportar CSV" :link="route('admin.reportes.csv', ['type'=>$type]+array_filter($filters,fn($v)=>$v!==''))" icon="o-arrow-down-tray" external /><p class="text-sm my-3">La exportación incluye todos los resultados de estos filtros. Inventario muestra movimientos históricos; calidad conserva cada análisis. Los reportes financieros excluyen liquidaciones anuladas.</p><div class="overflow-x-auto"><table class="table"><thead>@if($records->count())<tr>@foreach(array_keys((array)$records->first()) as $column)<th>{{ str_replace('_',' ',$column) }}</th>@endforeach</tr>@endif</thead><tbody>@forelse($records as $index=>$record)<tr wire:key="report-{{ $type }}-{{ $records->currentPage() }}-{{ $index }}">@foreach((array)$record as $value)<td>{{ $value }}</td>@endforeach</tr>@empty<tr><td>No hay registros para los filtros indicados.</td></tr>@endforelse</tbody></table></div>{{ $records->links() }}</x-card>@endif
</div>
