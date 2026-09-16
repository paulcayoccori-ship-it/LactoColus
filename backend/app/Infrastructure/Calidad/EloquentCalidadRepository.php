<?php

namespace App\Infrastructure\Calidad;

use App\Domain\Calidad\CalidadRepository;
use App\Infrastructure\Acopios\EntregaAcopio;
use App\Infrastructure\Acopios\JornadaAcopio;
use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Rutas\RutaAcopio;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

final class EloquentCalidadRepository implements CalidadRepository
{
    private function query(?int $ownerId): Builder
    {

        return AnalisisCalidad::query()->when($ownerId !== null, fn ($q) => $q->where('responsable_id', $ownerId))->with(['productor', 'ruta', 'responsable']);
    }

    public function listing(?int $ownerId, array $filters): LengthAwarePaginator
    {
        $query = $this->query($ownerId);
        foreach (['productor_id', 'ruta_id', 'responsable_id', 'estado'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (! empty($filters['desde'])) {
            $query->whereDate('muestra_at', '>=', $filters['desde']);
        }
        if (! empty($filters['hasta'])) {
            $query->whereDate('muestra_at', '<=', $filters['hasta']);
        }
        if (($filters['agua'] ?? '') !== '') {
            $query->where('agua_anadida', $filters['agua'] === 'si' ? '>' : '=', 0);
        }

        return $query->latest('muestra_at')->latest('id')->paginate(15);
    }

    public function find(?int $ownerId, string $uuid): AnalisisCalidad
    {
        return $this->query($ownerId)->with('auditorias.usuario')->where('uuid_publico', $uuid)->firstOrFail();
    }

    public function jornadas(array $filters): Collection
    {
        $date = $filters['fecha'] ?? now()->toDateString();

        return JornadaAcopio::query()
            ->with(['ruta', 'recolector', 'entregasRealizadas' => fn (HasMany $q) => $q->with('productor')->withCount('analisis')])
            ->withCount('entregasRealizadas')
            ->withSum('entregasRealizadas', 'litros')
            ->whereDate('fecha_operativa', $date)
            ->when($filters['estado'] ?? null, fn (Builder $q, string $estado) => $q->where('estado', $estado))
            ->when($filters['recolector_id'] ?? null, fn (Builder $q, int $id) => $q->where('recolector_id', $id))
            ->latest('id')
            ->get()
            ->map(fn (JornadaAcopio $jornada): array => $this->journeyForQuality($jornada));
    }

    private function journeyForQuality(JornadaAcopio $jornada): array
    {
        return [
            'id' => $jornada->id,
            'uuid_publico' => $jornada->uuid_publico,
            'ruta_id' => $jornada->ruta_id,
            'ruta_codigo' => $jornada->ruta?->codigo,
            'ruta' => $jornada->ruta?->codigo.' — '.$jornada->ruta?->nombre,
            'recolector_id' => $jornada->recolector_id,
            'recolector' => $jornada->recolector?->name,
            'fecha_operativa' => $jornada->fecha_operativa?->toDateString(),
            'turno' => $jornada->turno,
            'estado' => $jornada->estado,
            'litros' => (string) ($jornada->entregas_realizadas_sum_litros ?? '0.000'),
            'cantidad_entregas' => $jornada->entregas_realizadas_count,
            'entregas' => $jornada->entregasRealizadas->map(fn (EntregaAcopio $e): array => $this->deliveryForQuality($e))->all(),
        ];
    }

    private function deliveryForQuality(EntregaAcopio $delivery): array
    {
        return [
            'id' => $delivery->id,
            'productor_id' => $delivery->productor_id,
            'productor_codigo' => $delivery->productor?->codigo,
            'productor_nombres' => $delivery->productor?->nombres,
            'productor_apellidos' => $delivery->productor?->apellidos,
            'litros' => (string) $delivery->litros,
            'recolectada_at' => $delivery->recolectada_at?->toIso8601String(),
            'tiene_analisis' => $delivery->analisis_count > 0,
        ];
    }

    public function producers(?int $ownerId, string $search = ''): LengthAwarePaginator
    {

        return Productor::where('estado', true)->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('codigo', 'like', '%'.$search.'%')->orWhere('nombres', 'like', '%'.$search.'%')->orWhere('apellidos', 'like', '%'.$search.'%')))->orderBy('codigo')->paginate(30, ['id', 'codigo', 'nombres', 'apellidos']);
    }

    public function options(?int $ownerId, ?int $producerId = null): array
    {

        return [
            'productores_filtro' => Productor::withTrashed()->whereIn('id', $this->query($ownerId)->select('productor_id'))->orderBy('codigo')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->codigo.' — '.$p->nombres.' '.$p->apellidos])->all(),
            'productores' => Productor::where('estado', true)->orderBy('codigo')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->codigo.' — '.$p->nombres.' '.$p->apellidos])->all(),
            'rutas' => RutaAcopio::orderBy('codigo')->get(['id', 'nombre as name'])->toArray(),
            'responsables' => User::when($ownerId !== null, fn ($q) => $q->whereKey($ownerId))->whereIn('id', AnalisisCalidad::select('responsable_id'))->get(['id', 'name'])->toArray(),
            'entregas' => $producerId ? EntregaAcopio::where('productor_id', $producerId)->with('jornada')->latest('id')->get()->map(fn ($e) => ['id' => $e->id, 'name' => 'Entrega #'.$e->id.' — '.$e->jornada->fecha_operativa->toDateString()])->all() : [],
            'jornadas' => JornadaAcopio::where('estado', '!=', 'anulada')->with('ruta')->latest('id')->get()->map(fn ($j) => ['id' => $j->id, 'name' => '#'.$j->id.' — '.$j->ruta->codigo.' — '.$j->fecha_operativa->toDateString()])->all(),
        ];
    }

    public function profiles(?int $ownerId): LengthAwarePaginator
    {

        return PerfilCalidad::orderByDesc('version')->paginate(10);
    }

    public function profile(?int $ownerId, int $id): array
    {

        return PerfilCalidad::findOrFail($id)->toArray();
    }
}
