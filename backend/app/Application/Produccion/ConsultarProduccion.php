<?php

namespace App\Application\Produccion;

use App\Infrastructure\Operacion\AuditoriaOperativa;
use App\Infrastructure\Produccion\LoteProduccion;
use App\Infrastructure\Produccion\UsoRecepcion;
use App\Infrastructure\Recepciones\RecepcionPlanta;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

final class ConsultarProduccion
{
    public function authorize(int $actor): void
    {
        Gate::forUser(User::findOrFail($actor))->authorize('administrar-produccion');
    }

    public function listing(int $actor, array $filters = []): LengthAwarePaginator
    {
        $this->authorize($actor);

        return LoteProduccion::with(['responsable', 'alerta'])->withSum('ajustes', 'delta_moldes')->when($filters['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))->when($filters['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))->when($filters['buscar'] ?? null, fn ($q, $v) => $q->where('codigo', 'like', '%'.$v.'%'))->latest('producido_at')->latest('id')->paginate(15);
    }

    public function find(int $actor, string $uuid): LoteProduccion
    {
        $this->authorize($actor);

        return LoteProduccion::with(['responsable', 'usos.recepcion', 'ajustes', 'alerta'])->withSum('ajustes', 'delta_moldes')->where('uuid', $uuid)->firstOrFail();
    }

    public function options(int $actor): array
    {
        $this->authorize($actor);

        return RecepcionPlanta::where('resultado', '!=', 'anulada')->addSelect(['ocupado' => UsoRecepcion::selectRaw('COALESCE(SUM(litros),0)')->whereColumn('recepcion_id', 'recepciones_planta.id')->where('estado', '!=', 'liberado')])->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->uuid_publico.' — saldo '.bcsub($r->litros_planta, (string) ($r->ocupado ?? '0'), 3).' L'])->all();
    }

    public function audit(int $actor, string $uuid): array
    {
        $this->authorize($actor);

        return AuditoriaOperativa::with('usuario:id,name')->where('modulo', 'produccion')->where('entidad', $uuid)->latest('id')->get()->toArray();
    }
}
