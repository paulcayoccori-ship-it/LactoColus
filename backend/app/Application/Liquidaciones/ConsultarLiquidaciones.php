<?php

namespace App\Application\Liquidaciones;

use App\Application\Productores\IdentidadProductor;
use App\Infrastructure\Liquidaciones\Liquidacion;
use App\Infrastructure\Liquidaciones\PeriodoLiquidacion;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class ConsultarLiquidaciones
{
    public function __construct(private IdentidadProductor $identity) {}

    private function query(int $actor): Builder
    {
        $u = User::findOrFail($actor);
        $query = Liquidacion::with(['periodo', 'ajustes', 'pago']);
        if (! Gate::forUser($u)->allows('operar-liquidaciones')) {
            $query->where('productor_id', $this->identity->id($actor))->whereIn('estado', ['aprobada', 'pagada']);
        }

        return $query;
    }

    public function listing(int $actor, array $input = []): LengthAwarePaginator
    {
        $data = Validator::make($input, ['periodo' => ['nullable', 'uuid'], 'estado' => ['nullable', 'in:calculada,aprobada,pagada,anulada'], 'buscar' => ['nullable', 'string', 'max:100']])->validate();

        return $this->query($actor)->when($data['periodo'] ?? null, fn ($q, $v) => $q->whereHas('periodo', fn ($p) => $p->where('uuid', $v)))->when($data['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))->when($data['buscar'] ?? null, fn ($q, $v) => $q->where('productor_snapshot', 'like', '%'.$v.'%'))->orderByDesc('id')->paginate(20);
    }

    public function find(int $actor, string $uuid): Liquidacion
    {
        return $this->query($actor)->where('uuid', $uuid)->firstOrFail();
    }

    public function periods(int $actor): LengthAwarePaginator
    {
        Gate::forUser(User::findOrFail($actor))->authorize('operar-liquidaciones');

        return PeriodoLiquidacion::withCount('liquidaciones')->orderByDesc('desde')->paginate(20, ['*'], 'periodosPage');
    }
}
