<?php

namespace App\Application\Traslados;

use App\Application\Operacion\ReglasOperativas;
use App\Application\Productores\IdentidadProductor;
use App\Infrastructure\Operacion\AuditoriaOperativa;
use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Rutas\RutaAcopio;
use App\Infrastructure\Traslados\SolicitudTraslado;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ConsultarTraslados
{
    public function __construct(private IdentidadProductor $identity, private ReglasOperativas $rules) {}

    private function query(int $actor): Builder
    {
        $admin = User::findOrFail($actor)->isActiveAdministrator();

        return SolicitudTraslado::with(['productor', 'actual', 'solicitada'])->when(! $admin, fn ($q) => $q->where('productor_id', $this->identity->id($actor)));
    }

    public function listing(int $actor, string $state = ''): LengthAwarePaginator
    {
        return $this->query($actor)->when($state !== '', fn ($q) => $q->where('estado', $state))->latest('id')->paginate(15);
    }

    public function find(int $actor, string $uuid): SolicitudTraslado
    {
        return $this->query($actor)->where('uuid', $uuid)->firstOrFail();
    }

    public function options(int $actor): array
    {
        $admin = User::findOrFail($actor)->isActiveAdministrator();
        $own = $admin ? null : $this->identity->id($actor);

        return ['rutas' => RutaAcopio::where('estado', true)->orderBy('codigo')->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->codigo.' — '.$r->nombre])->all(), 'productores' => Productor::where('estado', true)->whereIn('id', DB::table('ruta_productor')->select('productor_id'))->when(! $admin, fn ($q) => $q->whereKey($own))->orderBy('codigo')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->codigo.' — '.$p->nombres.' '.$p->apellidos])->all(), 'regla' => $this->rules->current('traslados')];
    }

    public function audit(int $actor, string $uuid): array
    {
        abort_unless(User::findOrFail($actor)->isActiveAdministrator(), 403);

        return AuditoriaOperativa::with('usuario:id,name')->where('modulo', 'traslados')->where('entidad', $uuid)->latest('id')->get()->toArray();
    }
}
