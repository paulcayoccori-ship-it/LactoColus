<?php

namespace App\Application\Penalizaciones;

use App\Infrastructure\Operacion\AuditoriaOperativa;
use App\Infrastructure\Penalizaciones\AsistenciaTecnica;
use App\Infrastructure\Penalizaciones\SancionCalidad;
use App\Infrastructure\Productores\Productor;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ConsultarPenalizaciones
{
    public function __construct(private GestionarPenalizaciones $service) {}

    public function listing(int $actor, string $state = ''): LengthAwarePaginator
    {
        $this->service->authorize($actor);

        return SancionCalidad::with('productor')->when($state !== '', fn ($q) => $q->where('estado', $state))->latest('id')->paginate(15);
    }

    public function assistances(int $actor): LengthAwarePaginator
    {
        $this->service->authorize($actor);

        return AsistenciaTecnica::with('productor')->latest('id')->paginate(15, ['*'], 'asistencias');
    }

    public function find(int $actor, string $uuid): SancionCalidad
    {
        $this->service->authorize($actor);

        return SancionCalidad::with('productor')->where('uuid', $uuid)->firstOrFail();
    }

    public function assistance(int $actor, string $uuid): AsistenciaTecnica
    {
        $this->service->authorize($actor);

        return AsistenciaTecnica::with('productor')->where('uuid', $uuid)->firstOrFail();
    }

    public function options(int $actor): array
    {
        $this->service->authorize($actor);

        return ['productores' => Productor::withTrashed()->orderBy('codigo')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->codigo.' — '.$p->nombres.' '.$p->apellidos])->all(), 'usuarios' => User::where('active', true)->orderBy('name')->get(['id', 'name'])->toArray()];
    }

    public function audit(int $actor, string $uuid): array
    {
        $this->service->authorize($actor);

        return AuditoriaOperativa::with('usuario:id,name')->where('modulo', 'penalizaciones')->where('entidad', $uuid)->latest('id')->get()->toArray();
    }
}
