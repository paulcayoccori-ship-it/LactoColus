<?php

declare(strict_types=1);

namespace App\Infrastructure\Rutas;

use App\Domain\Rutas\RutaRepository;
use App\Infrastructure\Productores\Productor;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class EloquentRutaRepository implements RutaRepository
{
    public function paginate(string $search, ?bool $estado): LengthAwarePaginator
    {
        return RutaAcopio::query()->with('recolector.roles')->withCount('productores')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('codigo', 'like', '%'.$search.'%')->orWhere('nombre', 'like', '%'.$search.'%')))
            ->when($estado !== null, fn (Builder $q) => $q->where('estado', $estado))
            ->orderByDesc('id')->paginate(15)->through(fn (RutaAcopio $ruta): array => $this->attributes($ruta));
    }

    public function find(int $id, bool $lock = false): array
    {
        $ruta = RutaAcopio::query()->when($lock, fn (Builder $q) => $q->lockForUpdate())->with(['recolector.roles', 'productores'])->findOrFail($id);

        return $this->attributes($ruta) + ['productores' => $ruta->productores->map(fn (Productor $p): array => ['id' => $p->id, 'nombre' => $p->codigo.' — '.$p->nombres.' '.$p->apellidos, 'disponible' => $p->estado && ! $p->trashed(), 'orden' => $p->pivot->orden])->all()];
    }

    private function attributes(RutaAcopio $ruta): array
    {
        $user = $ruta->recolector;

        return ['id' => $ruta->id, 'codigo' => $ruta->codigo, 'nombre' => $ruta->nombre, 'descripcion' => $ruta->descripcion, 'estado' => $ruta->estado, 'recolector_id' => $ruta->recolector_id,
            'recolector' => $user?->name, 'recolector_disponible' => $user && $user->active && $user->hasRole('recolector', 'web'),
            'productores_count' => $ruta->productores_count ?? $ruta->productores->count()];
    }

    public function options(): array
    {
        return ['recolectores' => User::query()->where('active', true)->whereHas('roles', fn (Builder $query) => $query->where('name', 'recolector')->where('guard_name', 'web'))->orderBy('name')->get(['id', 'name'])->toArray(),
            'disponibles' => Productor::query()->where('estado', true)->whereNotIn('id', DB::table('ruta_productor')->select('productor_id'))->orderBy('apellidos')->get(['id', 'codigo', 'nombres', 'apellidos'])->map(fn (Productor $p): array => ['id' => $p->id, 'name' => $p->codigo.' — '.$p->nombres.' '.$p->apellidos])->all()];
    }

    public function save(?int $id, array $data): int
    {
        $ruta = $id === null ? new RutaAcopio : RutaAcopio::query()->findOrFail($id);
        $ruta->fill($data)->save();

        return $ruta->id;
    }

    public function collector(int $id): ?array
    {
        $user = User::query()->lockForUpdate()->with('roles')->find($id);

        return $user ? ['disponible' => $user->active && $user->hasRole('recolector', 'web')] : null;
    }

    public function producer(int $id): ?array
    {
        $p = Productor::withTrashed()->lockForUpdate()->find($id);

        return $p ? ['disponible' => $p->estado && ! $p->trashed()] : null;
    }

    public function assignment(int $productorId): ?array
    {
        $row = DB::table('ruta_productor')->where('productor_id', $productorId)->lockForUpdate()->first();

        return $row ? (array) $row : null;
    }

    public function attach(int $rutaId, int $productorId, int $orden): void
    {
        DB::table('ruta_productor')->insert(['ruta_id' => $rutaId, 'productor_id' => $productorId, 'orden' => $orden]);
    }

    public function detach(int $rutaId, int $productorId): void
    {
        DB::table('ruta_productor')->where('ruta_id', $rutaId)->where('productor_id', $productorId)->delete();
    }

    public function reorder(int $rutaId, array $ids): void
    {
        $offset = (int) DB::table('ruta_productor')->where('ruta_id', $rutaId)->max('orden') + 1;
        foreach ($ids as $index => $id) {
            DB::table('ruta_productor')->where('ruta_id', $rutaId)->where('productor_id', $id)->update(['orden' => $offset + $index]);
        }
        foreach ($ids as $index => $id) {
            DB::table('ruta_productor')->where('ruta_id', $rutaId)->where('productor_id', $id)->update(['orden' => $index + 1]);
        }
    }
}
