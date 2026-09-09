<?php

namespace App\Infrastructure\Acopios;

use App\Domain\Acopios\AcopioRepository;
use App\Infrastructure\Rutas\RutaAcopio;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class EloquentAcopioRepository implements AcopioRepository
{
    public function adminPaginate(array $filters): LengthAwarePaginator
    {
        return JornadaAcopio::query()->with(['ruta', 'recolector'])->withCount('entregas')->withSum('entregas', 'litros')
            ->when($filters['fecha_desde'] ?? null, fn (Builder $q, string $date) => $q->whereDate('fecha_operativa', '>=', $date))
            ->when($filters['fecha_hasta'] ?? null, fn (Builder $q, string $date) => $q->whereDate('fecha_operativa', '<=', $date))
            ->when($filters['ruta_id'] ?? null, fn (Builder $q, int $id) => $q->where('ruta_id', $id))
            ->when($filters['recolector_id'] ?? null, fn (Builder $q, int $id) => $q->where('recolector_id', $id))
            ->when($filters['estado'] ?? null, fn (Builder $q, string $state) => $q->where('estado', $state))
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->whereHas('ruta', fn (Builder $r) => $r->where('codigo', 'like', "%{$search}%")->orWhere('nombre', 'like', "%{$search}%")))
            ->latest('fecha_operativa')->latest('id')->paginate(15)->through(fn (JornadaAcopio $jornada): array => $this->summary($jornada));
    }

    public function adminFind(int $id): array
    {
        $jornada = JornadaAcopio::query()->with(['ruta', 'recolector', 'entregas.productor', 'entregas.recolector'])->findOrFail($id);

        return $this->detail($jornada);
    }

    public function routesForCollector(int $userId): array
    {
        return RutaAcopio::query()->where('recolector_id', $userId)->where('estado', true)->with('productores')->get()->map(fn (RutaAcopio $route): array => ['id' => $route->id, 'codigo' => $route->codigo, 'nombre' => $route->nombre, 'productores' => $route->productores->map(fn ($producer): array => ['id' => $producer->id, 'codigo' => $producer->codigo, 'nombres' => $producer->nombres, 'apellidos' => $producer->apellidos, 'orden' => $producer->pivot->orden])->values()->all()])->all();
    }

    public function findJourneyByPublicId(string $uuid, ?int $userId = null): ?array
    {
        $query = JornadaAcopio::query()->with(['ruta', 'recolector', 'entregas.productor'])->where('uuid_publico', $uuid);
        if ($userId !== null) {
            $query->where('recolector_id', $userId);
        }
        $jornada = $query->first();

        return $jornada ? $this->detail($jornada) : null;
    }

    public function createJourney(int $routeId, array $data): array
    {
        $jornada = JornadaAcopio::create($data + ['ruta_id' => $routeId]);

        return $this->detail($jornada->load(['ruta', 'recolector', 'entregas.productor']));
    }

    public function updateJourney(int $id, array $data): array
    {
        $jornada = JornadaAcopio::query()->lockForUpdate()->findOrFail($id);
        $jornada->fill($data)->save();

        return $this->detail($jornada->fresh()->load(['ruta', 'recolector', 'entregas.productor']));
    }

    public function addDelivery(int $userId, array $data): array
    {
        $jornada = JornadaAcopio::query()->lockForUpdate()->findOrFail($data['jornada_id']);
        $existing = EntregaAcopio::query()->where('uuid_cliente', $data['uuid_cliente'])->first();
        if ($existing) {
            abort_unless($existing->jornada()->where('recolector_id', $userId)->exists(), 403);

            return ['status' => 'repetido', 'entrega' => $this->delivery($existing->load(['productor', 'jornada']))];
        }
        abort_unless($jornada->recolector_id === $userId, 403);
        abort_unless($jornada->estado === 'abierta', 422, 'La jornada no está abierta.');
        abort_unless(DB::table('ruta_productor')->where('ruta_id', $jornada->ruta_id)->where('productor_id', $data['productor_id'])->exists(), 422, 'El productor no pertenece a la ruta de esta jornada.');
        if (EntregaAcopio::query()->where('jornada_id', $jornada->id)->where('productor_id', $data['productor_id'])->exists()) {
            abort(422, 'El productor ya tiene una entrega en esta jornada.');
        }
        try {
            $delivery = EntregaAcopio::create($data + ['ruta_id' => $jornada->ruta_id, 'recolector_id' => $userId, 'sincronizada_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            $delivery = EntregaAcopio::query()->where('uuid_cliente', $data['uuid_cliente'])->firstOrFail();

            return ['status' => 'repetido', 'entrega' => $this->delivery($delivery->load(['productor', 'jornada']))];
        }

        return ['status' => 'creado', 'entrega' => $this->delivery($delivery->load(['productor', 'jornada']))];
    }

    public function updateDelivery(int $id, array $data): array
    {
        $delivery = EntregaAcopio::query()->lockForUpdate()->with('jornada')->findOrFail($id);
        $old = $this->delivery($delivery);
        $delivery->fill($data)->save();

        return ['old' => $old, 'new' => $this->delivery($delivery->fresh()->load(['productor', 'jornada']))];
    }

    private function summary(JornadaAcopio $jornada): array
    {
        return ['id' => $jornada->id, 'uuid_publico' => $jornada->uuid_publico, 'ruta' => $jornada->ruta?->codigo.' — '.$jornada->ruta?->nombre, 'recolector' => $jornada->recolector?->name ?? 'Sin responsable', 'fecha_operativa' => $jornada->fecha_operativa?->toDateString(), 'turno' => $jornada->turno, 'estado' => $jornada->estado, 'productores_atendidos' => $jornada->entregas_count, 'litros' => (string) ($jornada->entregas_sum_litros ?? '0.000')];
    }

    private function detail(JornadaAcopio $jornada): array
    {
        return $this->summary($jornada) + ['observaciones' => $jornada->observaciones, 'iniciada_at' => $jornada->iniciada_at?->toIso8601String(), 'cerrada_at' => $jornada->cerrada_at?->toIso8601String(), 'ruta_id' => $jornada->ruta_id, 'recolector_id' => $jornada->recolector_id, 'entregas' => $jornada->entregas->map(fn (EntregaAcopio $e): array => $this->delivery($e))->all()];
    }

    private function delivery(EntregaAcopio $delivery): array
    {
        return ['id' => $delivery->id, 'uuid_cliente' => $delivery->uuid_cliente, 'jornada_id' => $delivery->jornada_id, 'ruta_id' => $delivery->ruta_id, 'productor_id' => $delivery->productor_id, 'productor' => $delivery->productor?->codigo.' — '.$delivery->productor?->nombres.' '.$delivery->productor?->apellidos, 'recolector_id' => $delivery->recolector_id, 'litros' => (string) $delivery->litros, 'recolectada_at' => $delivery->recolectada_at?->toIso8601String(), 'observacion' => $delivery->observacion, 'sincronizada_at' => $delivery->sincronizada_at?->toIso8601String()];
    }
}
