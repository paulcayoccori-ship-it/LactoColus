<?php

namespace App\Application\Comunicados;

use App\Infrastructure\Comunicados\Comunicado;
use App\Infrastructure\Comunicados\CuentaProductor;
use App\Infrastructure\Operacion\AuditoriaOperativa;
use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Rutas\RutaAcopio;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

final class ConsultarComunicados
{
    public function authorize(int $actor): void
    {
        Gate::forUser(User::findOrFail($actor))->authorize('administrar-comunicados');
    }

    public function inbox(int $actor): Builder
    {
        $user = User::with('roles')->findOrFail($actor);
        abort_unless($user->active, 403);
        $producer = $user->hasRole('productor', 'web') ? CuentaProductor::where('usuario_id', $actor)->where('activa', true)->whereIn('productor_id', Productor::where('estado', true)->select('id'))->value('productor_id') : null;
        $roles = $user->roles->where('guard_name', 'web')->where('name', '!=', 'productor')->pluck('name')->all();

        return Comunicado::whereIn('estado', ['publicado', 'programado'])->where('publicar_at', '<=', now())->where(fn ($q) => $q->whereNull('vence_at')->orWhere('vence_at', '>', now()))->where(function ($q) use ($producer, $roles): void {
            $q->whereRaw('1 = 0');
            if ($producer) {
                $q->orWhereIn('id', DB::table('comunicado_productor')->where('productor_id', $producer)->select('comunicado_id'));
            }
            foreach ($roles as $role) {
                $q->orWhere(fn ($q) => $q->where('audiencia', 'roles')->whereJsonContains('roles_destino', $role));
            }
        });
    }

    public function listing(int $actor): LengthAwarePaginator
    {
        return $this->inbox($actor)->select('comunicados.*')->addSelect(['leida_at' => DB::table('lecturas_comunicado')->whereColumn('comunicado_id', 'comunicados.id')->where('usuario_id', $actor)->select('leida_at')])->latest('publicar_at')->latest('id')->paginate(15);
    }

    public function find(int $actor, string $uuid): Comunicado
    {
        return $this->inbox($actor)->where('uuid', $uuid)->firstOrFail();
    }

    public function management(int $actor, string $search = ''): LengthAwarePaginator
    {
        $this->authorize($actor);

        return Comunicado::with('autor:id,name')->when($search !== '', fn ($q) => $q->where('titulo', 'like', '%'.$search.'%'))->latest('id')->paginate(15);
    }

    public function detail(int $actor, string $uuid): array
    {
        $this->authorize($actor);
        $record = Comunicado::with('autor:id,name')->where('uuid', $uuid)->firstOrFail();

        return ['record' => $record, 'productores' => DB::table('comunicado_productor')->where('comunicado_id', $record->id)->pluck('productor_id')->all(), 'lecturas' => DB::table('lecturas_comunicado')->join('users', 'users.id', '=', 'usuario_id')->where('comunicado_id', $record->id)->orderBy('leida_at')->get(['users.name', 'leida_at'])->toArray(), 'audit' => AuditoriaOperativa::with('usuario:id,name')->where('modulo', 'comunicados')->where('entidad', $uuid)->latest('id')->get()->toArray()];
    }

    public function options(int $actor): array
    {
        $this->authorize($actor);

        return ['productores' => Productor::where('estado', true)->orderBy('codigo')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->codigo.' — '.$p->nombres.' '.$p->apellidos])->all(), 'rutas' => RutaAcopio::where('estado', true)->orderBy('codigo')->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->codigo.' — '.$r->nombre])->all(), 'roles' => Role::where('guard_name', 'web')->where('name', '!=', 'productor')->orderBy('name')->get()->map(fn ($r) => ['id' => $r->name, 'name' => ucfirst($r->name)])->all(), 'cuentas' => User::where('active', true)->role('productor', 'web')->orderBy('name')->get(['id', 'name'])->toArray(), 'vinculos' => CuentaProductor::join('users', 'users.id', '=', 'usuario_id')->join('productores', 'productores.id', '=', 'productor_id')->orderBy('cuentas_productor.id')->get(['usuario_id', 'productor_id', 'activa', 'users.name', 'productores.codigo'])->toArray()];
    }
}
