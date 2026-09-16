<?php
use App\Application\Ranking\ConsultarRanking;
use Livewire\Component;
use Livewire\Attributes\Layout;
new #[Layout('layouts.public')] class extends Component {
 public string $tipo='diario'; public string $fecha=''; public string $ruta='';
 public function mount(): void { $this->fecha=today()->toDateString(); }
 public function with(): array { $q=app(ConsultarRanking::class); try { $data=$q->publicData(['tipo'=>$this->tipo,'fecha'=>$this->fecha,'ruta_id'=>$this->ruta===''?null:$this->ruta]); } catch(\Illuminate\Validation\ValidationException $e) { $data=['periodo'=>null,'resultados'=>[],'message'=>'Selecciona un periodo y una fecha válidos.']; } return ['ranking'=>$data,'rutas'=>$q->routes()]; }
}; ?>
<div class="space-y-6"><x-header title="Muro de Honor" subtitle="Resultados de calidad del periodo" separator /><x-card shadow><div class="grid md:grid-cols-3 gap-3"><x-select label="Periodo" wire:model.live="tipo" :options="[['id'=>'diario','name'=>'Diario'],['id'=>'semanal','name'=>'Semanal'],['id'=>'mensual','name'=>'Mensual']]" /><x-input label="Fecha del periodo" type="date" wire:model.live="fecha" /><x-select label="Ruta" wire:model.live="ruta" :options="$rutas" placeholder="Todas" /></div></x-card>
 @if($ranking['periodo'])<p>{{ $ranking['periodo']['desde'] }} — {{ $ranking['periodo']['hasta'] }} · Versión {{ $ranking['periodo']['version_reglas'] }}</p>@endif
 <x-card shadow><div class="overflow-x-auto"><table class="table"><thead><tr><th>Posición</th><th>Productor</th><th>Ruta histórica</th><th>Puntuación / 100</th></tr></thead><tbody>@forelse($ranking['resultados'] as $row)<tr wire:key="honor-{{ $row['posicion'] }}"><td>{{ $row['posicion'] }}</td><td>{{ $row['productor'] }}</td><td>{{ implode(', ',array_column($row['rutas'],'nombre')) ?: 'Sin ruta' }}</td><td>{{ $row['puntuacion'] }}</td></tr>@empty<tr><td colspan="4" class="text-center py-8">{{ $ranking['message'] ?? 'No hay resultados completos para este periodo.' }}</td></tr>@endforelse</tbody></table></div></x-card>
</div>
