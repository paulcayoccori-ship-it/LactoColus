<?php

namespace App\Infrastructure\Recepciones;

use App\Domain\Recepciones\RecepcionRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class EloquentRecepcionRepository implements RecepcionRepository
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        return RecepcionPlanta::query()->with(['ruta', 'recolector', 'jornada', 'alerta'])->when($filters['desde'] ?? null, fn (Builder $q, string $date) => $q->whereDate('recibida_at', '>=', $date))->when($filters['hasta'] ?? null, fn (Builder $q, string $date) => $q->whereDate('recibida_at', '<=', $date))->when($filters['ruta_id'] ?? null, fn (Builder $q, int $id) => $q->where('ruta_id', $id))->when($filters['recolector_id'] ?? null, fn (Builder $q, int $id) => $q->where('recolector_id', $id))->when($filters['resultado'] ?? null, fn (Builder $q, string $state) => $q->where('resultado', $state))->latest('recibida_at')->latest('id')->paginate(15)->through(fn (RecepcionPlanta $reception): array => $this->attributes($reception));
    }

    public function find(int $id): array
    {
        return $this->detail(RecepcionPlanta::query()->with(['ruta', 'recolector', 'registradaPor', 'jornada', 'alerta.revisadaPor'])->findOrFail($id));
    }

    public function pendingAlerts(): array
    {
        return AlertaConciliacion::query()->where('estado', 'pendiente')->with(['recepcion.ruta', 'revisadaPor'])->latest()->get()->map(fn (AlertaConciliacion $alert): array => $this->alert($alert))->all();
    }

    public function tolerance(): ?string
    {
        return ConfiguracionAcopio::query()->where('clave', 'tolerancia_conciliacion_porcentaje')->value('valor');
    }

    public function saveTolerance(string $value, int $userId): void
    {
        ConfiguracionAcopio::query()->updateOrCreate(['clave' => 'tolerancia_conciliacion_porcentaje'], ['valor' => $value, 'actualizado_por' => $userId]);
    }

    public function byExternalUuid(string $uuid): ?array
    {
        $reception = RecepcionPlanta::query()->with(['ruta', 'recolector', 'alerta'])->where('uuid_lectura_externa', $uuid)->first();

        return $reception ? $this->detail($reception) : null;
    }

    public function create(array $data): array
    {
        return $this->detail(RecepcionPlanta::create($data)->load(['ruta', 'recolector', 'alerta']));
    }

    public function update(int $id, array $data): array
    {
        $reception = RecepcionPlanta::query()->lockForUpdate()->findOrFail($id);
        $reception->fill($data)->save();

        return $this->detail($reception->fresh()->load(['ruta', 'recolector', 'alerta']));
    }

    public function updateAlert(int $receptionId, array $data): void
    {
        AlertaConciliacion::query()->updateOrCreate(['recepcion_id' => $receptionId], $data);
    }

    public function resolveAlert(int $alertId, int $userId, string $comment): void
    {
        AlertaConciliacion::query()->lockForUpdate()->findOrFail($alertId)->update(['estado' => 'resuelta', 'revisada_por' => $userId, 'revisada_at' => now(), 'comentario_resolucion' => $comment]);
    }

    private function attributes(RecepcionPlanta $reception): array
    {
        return ['id' => $reception->id, 'uuid_publico' => $reception->uuid_publico, 'jornada_id' => $reception->jornada_id, 'fecha_operativa' => $reception->jornada?->fecha_operativa?->toDateString(), 'turno' => $reception->jornada?->turno, 'ruta' => $reception->ruta?->codigo.' — '.$reception->ruta?->nombre, 'recolector' => $reception->recolector?->name ?? 'Sin responsable', 'litros_campo' => (string) $reception->litros_campo, 'litros_planta' => (string) $reception->litros_planta, 'diferencia_litros' => (string) $reception->diferencia_litros, 'diferencia_porcentaje' => $reception->diferencia_porcentaje === null ? null : (string) $reception->diferencia_porcentaje, 'tolerancia_porcentaje' => (string) $reception->tolerancia_porcentaje, 'resultado' => $reception->resultado, 'recibida_at' => $reception->recibida_at?->toIso8601String(), 'fuente_medicion' => $reception->fuente_medicion, 'observaciones' => $reception->observaciones, 'registrada_por' => $reception->registradaPor?->name, 'alerta' => $reception->alerta ? $this->alert($reception->alerta) : null];
    }

    private function detail(RecepcionPlanta $reception): array
    {
        return $this->attributes($reception) + ['jornada_estado' => $reception->jornada?->estado, 'alertas' => $reception->alerta ? [$this->alert($reception->alerta)] : []];
    }

    private function alert(AlertaConciliacion $alert): array
    {
        return ['id' => $alert->id, 'recepcion_id' => $alert->recepcion_id, 'litros_campo' => (string) $alert->litros_campo, 'litros_planta' => (string) $alert->litros_planta, 'diferencia_litros' => (string) $alert->diferencia_litros, 'diferencia_porcentaje' => $alert->diferencia_porcentaje === null ? null : (string) $alert->diferencia_porcentaje, 'estado' => $alert->estado, 'comentario_resolucion' => $alert->comentario_resolucion, 'revisada_por' => $alert->revisadaPor?->name, 'revisada_at' => $alert->revisada_at?->toIso8601String()];
    }
}
