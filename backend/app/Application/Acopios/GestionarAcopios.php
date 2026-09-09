<?php

namespace App\Application\Acopios;

use App\Domain\Acopios\AcopioRepository;
use App\Infrastructure\Acopios\AuditoriaAcopio;
use App\Infrastructure\Acopios\EntregaAcopio;
use App\Infrastructure\Acopios\JornadaAcopio;
use App\Infrastructure\Rutas\RutaAcopio;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class GestionarAcopios
{
    public function __construct(private AcopioRepository $repository) {}

    public function createJourney(int $actorId, array $input, bool $field = false): array
    {
        if (! $field) {
            Gate::forUser(User::findOrFail($actorId))->authorize('administrar-acopios');
        }
        $data = Validator::make($input, ['ruta_id' => ['required', 'integer', 'exists:rutas_acopio,id'], 'fecha_operativa' => ['required', 'date'], 'turno' => ['required', Rule::in(['primera_vuelta', 'segunda_vuelta'])], 'observaciones' => ['nullable', 'string', 'max:5000']], ['required' => 'El campo :attribute es obligatorio.', 'date' => 'La fecha operativa no es válida.', 'integer' => 'Selecciona una ruta válida.', 'exists' => 'La ruta seleccionada no existe.', 'in' => 'Selecciona un turno válido.', 'max' => 'Las observaciones superan la longitud permitida.'], ['ruta_id' => 'ruta', 'fecha_operativa' => 'fecha operativa', 'turno' => 'turno', 'observaciones' => 'observaciones'])->validate();
        $route = RutaAcopio::query()->with('recolector')->findOrFail($data['ruta_id']);
        if ($field) {
            abort_unless($route->recolector_id === $actorId && $route->estado && $route->recolector?->active && $route->recolector->hasRole('recolector', 'web'), 403);
        }
        if (! $route->recolector_id) {
            throw ValidationException::withMessages(['ruta_id' => 'La ruta no tiene recolector responsable.']);
        }

        try {
            return DB::transaction(fn (): array => $this->repository->createJourney($route->id, ['uuid_publico' => (string) Str::uuid(), 'recolector_id' => $route->recolector_id, 'fecha_operativa' => $data['fecha_operativa'], 'turno' => $data['turno'], 'iniciada_at' => now(), 'estado' => 'abierta', 'observaciones' => $data['observaciones'] ?? null]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['fecha_operativa' => 'Ya existe una jornada para esa ruta, fecha y turno.']);
        }
    }

    public function changeState(int $actorId, int $id, string $state, ?string $motivo = null): array
    {
        Gate::forUser(User::findOrFail($actorId))->authorize('administrar-acopios');
        if (! in_array($state, ['cerrada', 'anulada'], true)) {
            throw ValidationException::withMessages(['estado' => 'El estado indicado no es válido.']);
        }

        return DB::transaction(function () use ($actorId, $id, $state, $motivo): array {
            $jornada = JornadaAcopio::query()->lockForUpdate()->findOrFail($id);
            if ($jornada->estado !== 'abierta') {
                throw ValidationException::withMessages(['estado' => 'Solo una jornada abierta puede cerrarse o anularse.']);
            }
            if ($state === 'anulada' && trim((string) $motivo) === '') {
                throw ValidationException::withMessages(['motivo' => 'Indica el motivo de la anulación.']);
            }
            $old = ['estado' => $jornada->estado, 'cerrada_at' => $jornada->cerrada_at?->toIso8601String()];
            $jornada->estado = $state;
            $jornada->cerrada_at = now();
            $jornada->save();
            AuditoriaAcopio::create(['jornada_id' => $id, 'usuario_id' => $actorId, 'accion' => $state === 'anulada' ? 'anulacion' : 'cierre', 'datos_anteriores' => $old, 'datos_nuevos' => ['estado' => $state], 'motivo' => $motivo]);

            return $this->repository->adminFind($id);
        });
    }

    public function sync(int $actorId, array $input): array
    {
        $user = User::findOrFail($actorId);
        abort_unless($user->active && $user->hasRole('recolector', 'web'), 403);
        $results = ['creados' => [], 'repetidos' => [], 'rechazados' => []];
        foreach ($input as $index => $item) {
            try {
                $data = Validator::make($item, ['uuid_cliente' => ['required', 'uuid'], 'jornada_id' => ['required', 'integer', 'exists:jornadas_acopio,id'], 'productor_id' => ['required', 'integer', 'exists:productores,id'], 'litros' => ['required', 'numeric', 'gt:0', 'max:9999999.999'], 'recolectada_at' => ['required', 'date'], 'observacion' => ['nullable', 'string', 'max:2000']], ['required' => 'El campo :attribute es obligatorio.', 'uuid' => 'El UUID de cliente no es válido.', 'integer' => 'El identificador no es válido.', 'exists' => 'El registro relacionado no existe.', 'numeric' => 'Los litros deben ser numéricos.', 'gt' => 'Los litros deben ser mayores que cero.', 'max' => 'Los litros superan el máximo permitido.', 'date' => 'La fecha de recolección no es válida.'], ['uuid_cliente' => 'UUID de cliente', 'jornada_id' => 'jornada', 'productor_id' => 'productor', 'litros' => 'litros', 'recolectada_at' => 'fecha de recolección', 'observacion' => 'observación'])->validate();
                $result = DB::transaction(fn (): array => $this->repository->addDelivery($actorId, $data), 5);
                $results[$result['status'] === 'creado' ? 'creados' : 'repetidos'][] = ['indice' => $index, 'entrega' => $result['entrega']];
            } catch (\Throwable $exception) {
                $results['rechazados'][] = ['indice' => $index, 'errores' => $exception instanceof ValidationException ? $exception->errors() : ['general' => [$exception instanceof HttpException ? $exception->getMessage() : 'No se pudo procesar la entrega.']]];
            }
        }

        return $results;
    }

    public function correctDelivery(int $actorId, int $id, array $input): array
    {
        Gate::forUser(User::findOrFail($actorId))->authorize('administrar-acopios');
        $data = Validator::make($input, ['litros' => ['required', 'numeric', 'gt:0', 'max:9999999.999'], 'observacion' => ['nullable', 'string', 'max:2000'], 'motivo' => ['required', 'string', 'max:1000']], ['required' => 'El campo :attribute es obligatorio.', 'numeric' => 'Los litros deben ser numéricos.', 'gt' => 'Los litros deben ser mayores que cero.', 'max' => 'El campo :attribute supera el máximo permitido.'], ['litros' => 'litros', 'observacion' => 'observación', 'motivo' => 'motivo'])->validate();

        return DB::transaction(function () use ($actorId, $id, $data): array {
            $delivery = EntregaAcopio::query()->with('jornada')->lockForUpdate()->findOrFail($id);
            if ($delivery->jornada->estado !== 'abierta') {
                throw ValidationException::withMessages(['litros' => 'La jornada está cerrada o anulada y no admite cambios.']);
            }
            $old = ['litros' => (string) $delivery->litros, 'observacion' => $delivery->observacion];
            $result = $this->repository->updateDelivery($id, ['litros' => $data['litros'], 'observacion' => $data['observacion'] ?? null]);
            AuditoriaAcopio::create(['jornada_id' => $delivery->jornada_id, 'entrega_id' => $id, 'usuario_id' => $actorId, 'accion' => 'correccion', 'datos_anteriores' => $old, 'datos_nuevos' => ['litros' => (string) $data['litros'], 'observacion' => $data['observacion'] ?? null], 'motivo' => $data['motivo']]);

            return $result['new'];
        });
    }
}
