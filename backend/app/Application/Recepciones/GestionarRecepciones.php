<?php

namespace App\Application\Recepciones;

use App\Domain\Recepciones\RecepcionRepository;
use App\Infrastructure\Acopios\EntregaAcopio;
use App\Infrastructure\Acopios\JornadaAcopio;
use App\Infrastructure\Recepciones\AlertaConciliacion;
use App\Infrastructure\Recepciones\AuditoriaRecepcion;
use App\Infrastructure\Recepciones\RecepcionPlanta;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class GestionarRecepciones
{
    public function __construct(private RecepcionRepository $repository) {}

    public function saveTolerance(int $actorId, mixed $value): void
    {
        Gate::forUser(User::findOrFail($actorId))->authorize('administrar-recepciones');
        $data = Validator::make(['valor' => $value], ['valor' => ['required', 'numeric', 'gte:0', 'max:100']], ['required' => 'Define una tolerancia.', 'numeric' => 'La tolerancia debe ser numérica.', 'gte' => 'La tolerancia no puede ser negativa.', 'max' => 'La tolerancia no puede superar 100%.'])->validate();
        $this->repository->saveTolerance((string) $data['valor'], $actorId);
    }

    public function create(int $actorId, array $input, bool $api = false): array
    {
        $actor = User::findOrFail($actorId);
        if ($api) {
            abort_unless($actor->active && ($actor->isActiveAdministrator() || $actor->hasRole('recolector', 'web')), 403);
        } else {
            Gate::forUser($actor)->authorize('administrar-recepciones');
        }
        $data = Validator::make($input, ['jornada_id' => ['required', 'integer', 'exists:jornadas_acopio,id'], 'litros_planta' => ['required', 'numeric', 'gt:0', 'max:999999999.999'], 'recibida_at' => ['required', 'date'], 'fuente_medicion' => ['required', Rule::in(['manual', 'sensor'])], 'uuid_lectura_externa' => ['nullable', 'uuid'], 'observaciones' => ['nullable', 'string', 'max:5000']], ['required' => 'El campo :attribute es obligatorio.', 'exists' => 'La jornada no existe.', 'integer' => 'La jornada no es válida.', 'numeric' => 'Los litros deben ser numéricos.', 'gt' => 'Los litros deben ser mayores que cero.', 'max' => 'El campo :attribute supera el máximo permitido.', 'date' => 'La fecha de recepción no es válida.', 'in' => 'La fuente de medición no es válida.', 'uuid' => 'El identificador externo no es válido.'], ['jornada_id' => 'jornada', 'litros_planta' => 'litros medidos en planta', 'recibida_at' => 'fecha de recepción', 'fuente_medicion' => 'fuente de medición', 'uuid_lectura_externa' => 'UUID externo', 'observaciones' => 'observaciones'])->validate();
        if (! empty($data['uuid_lectura_externa'])) {
            $existingModel = RecepcionPlanta::query()->where('uuid_lectura_externa', $data['uuid_lectura_externa'])->first();
            if ($existingModel) {
                abort_unless($actor->isActiveAdministrator() || $existingModel->recolector_id === $actorId, 403);

                return ['estado_sincronizacion' => 'repetida', 'recepcion' => $this->repository->byExternalUuid($data['uuid_lectura_externa'])];
            }
        }
        $tolerance = $this->repository->tolerance();
        if ($tolerance === null) {
            throw ValidationException::withMessages(['tolerancia' => 'Define la tolerancia de conciliación antes de registrar una recepción.']);
        }

        return DB::transaction(function () use ($actor, $actorId, $data, $tolerance, $api): array {
            $journey = JornadaAcopio::query()->lockForUpdate()->with('ruta')->findOrFail($data['jornada_id']);
            if ($journey->estado !== 'cerrada') {
                throw ValidationException::withMessages(['jornada_id' => 'Solo se puede recibir una jornada cerrada.']);
            }
            if ($api && ! $actor->isActiveAdministrator()) {
                abort_unless($journey->recolector_id === $actorId, 403);
            }
            $field = (string) EntregaAcopio::query()->where('jornada_id', $journey->id)->sum('litros');
            $plant = (string) $data['litros_planta'];
            $difference = bcsub($plant, $field, 3);
            $absoluteDifference = bccomp($difference, '0', 3) < 0 ? bcsub('0', $difference, 3) : $difference;
            $percentage = bccomp($field, '0', 3) === 0 ? '100.000' : bcmul(bcdiv($absoluteDifference, $field, 6), '100', 3);
            $result = bccomp($percentage, (string) $tolerance, 3) <= 0 ? 'dentro_tolerancia' : 'con_diferencia';
            try {
                $reception = RecepcionPlanta::create(['uuid_publico' => (string) Str::uuid(), 'uuid_lectura_externa' => $data['uuid_lectura_externa'] ?? null, 'jornada_id' => $journey->id, 'ruta_id' => $journey->ruta_id, 'recolector_id' => $journey->recolector_id, 'litros_campo' => $field, 'litros_planta' => $plant, 'diferencia_litros' => $difference, 'diferencia_porcentaje' => $percentage, 'tolerancia_porcentaje' => $tolerance, 'resultado' => $result, 'recibida_at' => $data['recibida_at'], 'fuente_medicion' => $data['fuente_medicion'], 'observaciones' => $data['observaciones'] ?? null, 'registrada_por' => $actorId]);
            } catch (UniqueConstraintViolationException) {
                if (! empty($data['uuid_lectura_externa']) && ($existingModel = RecepcionPlanta::query()->where('uuid_lectura_externa', $data['uuid_lectura_externa'])->first())) {
                    abort_unless($actor->isActiveAdministrator() || $existingModel->recolector_id === $actorId, 403);

                    return ['estado_sincronizacion' => 'repetida', 'recepcion' => $this->repository->byExternalUuid($data['uuid_lectura_externa'])];
                } throw ValidationException::withMessages(['jornada_id' => 'La jornada ya tiene una recepción vigente.']);
            }
            if ($result === 'con_diferencia') {
                AlertaConciliacion::create(['recepcion_id' => $reception->id, 'litros_campo' => $field, 'litros_planta' => $plant, 'diferencia_litros' => $difference, 'diferencia_porcentaje' => $percentage, 'estado' => 'pendiente']);
            }

            return ['estado_sincronizacion' => 'creada', 'recepcion' => $this->repository->find($reception->id)];
        }, 5);
    }

    public function correct(int $actorId, int $id, array $input): array
    {
        Gate::forUser(User::findOrFail($actorId))->authorize('administrar-recepciones');
        $data = Validator::make($input, ['litros_planta' => ['required', 'numeric', 'gt:0', 'max:999999999.999'], 'motivo' => ['required', 'string', 'max:1000'], 'observaciones' => ['nullable', 'string', 'max:5000']], ['required' => 'El campo :attribute es obligatorio.', 'numeric' => 'Los litros deben ser numéricos.', 'gt' => 'Los litros deben ser mayores que cero.', 'max' => 'El campo :attribute supera el máximo permitido.'], ['litros_planta' => 'litros medidos en planta', 'motivo' => 'motivo', 'observaciones' => 'observaciones'])->validate();

        return DB::transaction(function () use ($actorId, $id, $data): array {
            $reception = RecepcionPlanta::query()->lockForUpdate()->with('jornada')->findOrFail($id);
            if ($reception->resultado === 'anulada') {
                throw ValidationException::withMessages(['litros_planta' => 'Una recepción anulada no admite correcciones.']);
            }
            $field = (string) EntregaAcopio::query()->where('jornada_id', $reception->jornada_id)->sum('litros');
            $plant = (string) $data['litros_planta'];
            $difference = bcsub($plant, $field, 3);
            $absoluteDifference = bccomp($difference, '0', 3) < 0 ? bcsub('0', $difference, 3) : $difference;
            $percentage = bccomp($field, '0', 3) === 0 ? '100.000' : bcmul(bcdiv($absoluteDifference, $field, 6), '100', 3);
            $result = bccomp($percentage, (string) $reception->tolerancia_porcentaje, 3) <= 0 ? 'dentro_tolerancia' : 'con_diferencia';
            $old = ['litros_planta' => (string) $reception->litros_planta, 'resultado' => $reception->resultado, 'diferencia_litros' => (string) $reception->diferencia_litros];
            $reception->update(['litros_campo' => $field, 'litros_planta' => $plant, 'diferencia_litros' => $difference, 'diferencia_porcentaje' => $percentage, 'resultado' => $result, 'observaciones' => $data['observaciones'] ?? $reception->observaciones]);
            $alert = AlertaConciliacion::query()->where('recepcion_id', $id)->first();
            if ($result === 'con_diferencia') {
                $this->repository->updateAlert($id, ['litros_campo' => $field, 'litros_planta' => $plant, 'diferencia_litros' => $difference, 'diferencia_porcentaje' => $percentage, 'estado' => $alert?->estado ?? 'pendiente']);
            } elseif ($alert) {
                $alert->update(['litros_campo' => $field, 'litros_planta' => $plant, 'diferencia_litros' => $difference, 'diferencia_porcentaje' => $percentage, 'estado' => 'resuelta', 'revisada_por' => $actorId, 'revisada_at' => now(), 'comentario_resolucion' => 'Resuelta al corregir la recepción.']);
            }
            AuditoriaRecepcion::create(['recepcion_id' => $id, 'usuario_id' => $actorId, 'accion' => 'correccion', 'datos_anteriores' => $old, 'datos_nuevos' => ['litros_planta' => $plant, 'resultado' => $result, 'diferencia_litros' => $difference], 'motivo' => $data['motivo']]);

            return $this->repository->find($id);
        }, 5);
    }

    public function annul(int $actorId, int $id, string $reason): array
    {
        Gate::forUser(User::findOrFail($actorId))->authorize('administrar-recepciones');
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['motivo' => 'El motivo de anulación es obligatorio.']);
        }

        return DB::transaction(function () use ($actorId, $id, $reason): array {
            $reception = RecepcionPlanta::query()->lockForUpdate()->findOrFail($id);
            if ($reception->resultado === 'anulada') {
                throw ValidationException::withMessages(['resultado' => 'La recepción ya está anulada.']);
            } $old = ['resultado' => $reception->resultado];
            $reception->update(['resultado' => 'anulada']);
            AlertaConciliacion::query()->where('recepcion_id', $id)->where('estado', 'pendiente')->update(['estado' => 'resuelta', 'revisada_por' => $actorId, 'revisada_at' => now(), 'comentario_resolucion' => 'Recepción anulada: '.$reason]);
            AuditoriaRecepcion::create(['recepcion_id' => $id, 'usuario_id' => $actorId, 'accion' => 'anulacion', 'datos_anteriores' => $old, 'datos_nuevos' => ['resultado' => 'anulada'], 'motivo' => $reason]);

            return $this->repository->find($id);
        }, 5);
    }

    public function resolveAlert(int $actorId, int $alertId, string $comment): void
    {
        Gate::forUser(User::findOrFail($actorId))->authorize('administrar-recepciones');
        if (trim($comment) === '') {
            throw ValidationException::withMessages(['comentario' => 'El comentario de resolución es obligatorio.']);
        } DB::transaction(fn () => $this->repository->resolveAlert($alertId, $actorId, $comment), 5);
    }
}
