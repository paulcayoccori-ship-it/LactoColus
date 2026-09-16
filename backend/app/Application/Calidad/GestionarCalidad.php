<?php

namespace App\Application\Calidad;

use App\Application\Penalizaciones\GestionarPenalizaciones;
use App\Application\Ranking\InvalidarRanking;
use App\Domain\Calidad\CorrectorDensidad;
use App\Domain\Calidad\ParametrosCalidad;
use App\Domain\Usuarios\UsuarioRepository;
use App\Infrastructure\Acopios\EntregaAcopio;
use App\Infrastructure\Acopios\JornadaAcopio;
use App\Infrastructure\Calidad\AnalisisCalidad;
use App\Infrastructure\Calidad\AuditoriaCalidad;
use App\Infrastructure\Calidad\PerfilCalidad;
use App\Infrastructure\Productores\Productor;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class GestionarCalidad
{
    public function __construct(private UsuarioRepository $users, private CorrectorDensidad $density) {}

    public function register(int $actorId, array $input): array
    {
        Gate::forUser(User::findOrFail($actorId))->authorize('operar-calidad');
        $uuid = Validator::make($input, ['uuid_externo' => ['required', 'uuid']], ParametrosCalidad::messages())->validate()['uuid_externo'];
        if ($existing = AnalisisCalidad::where('uuid_externo', $uuid)->first()) {
            return $this->repeated($actorId, $existing);
        }
        $input = $this->emptyToNull($input);
        $data = Validator::make($input, ParametrosCalidad::rules(), ParametrosCalidad::messages(), ParametrosCalidad::attributes())->validate();
        try {
            return $this->users->underAdminLock(function () use ($actorId, $data): array {
                Gate::forUser(User::findOrFail($actorId))->authorize('operar-calidad');
                if ($existing = AnalisisCalidad::where('uuid_externo', $data['uuid_externo'])->lockForUpdate()->first()) {
                    return $this->repeated($actorId, $existing);
                }
                $producer = Productor::whereKey($data['productor_id'])->lockForUpdate()->first();
                if (! $producer || ! $producer->estado) {
                    throw ValidationException::withMessages(['productor_id' => 'Selecciona un productor activo y no eliminado.']);
                }
                $delivery = empty($data['entrega_id']) ? null : EntregaAcopio::whereKey($data['entrega_id'])->lockForUpdate()->first();
                if (! empty($data['entrega_id']) && (! $delivery || $delivery->productor_id !== $producer->id)) {
                    throw ValidationException::withMessages(['entrega_id' => 'La entrega no pertenece al productor.']);
                }
                if ($delivery && ! empty($data['jornada_id']) && $delivery->jornada_id !== (int) $data['jornada_id']) {
                    throw ValidationException::withMessages(['jornada_id' => 'La jornada no coincide con la entrega.']);
                }
                $journeyId = $delivery?->jornada_id ?? ($data['jornada_id'] ?? null);
                $journey = $journeyId ? JornadaAcopio::whereKey($journeyId)->lockForUpdate()->first() : null;
                if ($journeyId && (! $journey || $journey->estado === 'anulada')) {
                    throw ValidationException::withMessages(['jornada_id' => 'La jornada no existe o está anulada.']);
                }
                $assignment = DB::table('ruta_productor')->where('productor_id', $producer->id)->first();
                if ($journey && ! $delivery && ($assignment?->ruta_id !== $journey->ruta_id)) {
                    throw ValidationException::withMessages(['jornada_id' => 'El productor no pertenece a la ruta de la jornada.']);
                }
                $data['jornada_id'] = $journeyId;
                $data['ruta_id'] = $delivery?->ruta_id ?? $journey?->ruta_id ?? $assignment?->ruta_id;
                $data = $this->normalize($data);
                $profile = $this->profileFor($data['muestra_at']);
                $analysis = AnalisisCalidad::create($data + ['uuid_publico' => (string) Str::uuid(), 'responsable_id' => $actorId, 'sincronizada_at' => now()] + $this->evaluate($data, $profile));

                app(InvalidarRanking::class)->analysis($analysis);
                app(GestionarPenalizaciones::class)->capture($analysis, $actorId);

                return ['estado' => 'creado', 'analisis' => $analysis];
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = AnalisisCalidad::where('uuid_externo', $uuid)->first();
            if (! $existing) {
                throw $exception;
            }

            return $this->repeated($actorId, $existing);
        }
    }

    private function repeated(int $actorId, AnalisisCalidad $analysis): array
    {
        abort_unless(User::findOrFail($actorId)->isActiveAdministrator() || $analysis->responsable_id === $actorId, 403, 'No puedes consultar un análisis ajeno.');

        return ['estado' => 'repetido', 'analisis' => $analysis];
    }

    public function sync(int $actorId, array $items): array
    {
        Gate::forUser(User::findOrFail($actorId))->authorize('operar-calidad');
        $result = ['creados' => [], 'repetidos' => [], 'rechazados' => []];
        foreach ($items as $index => $item) {
            try {
                if (! is_array($item)) {
                    throw ValidationException::withMessages(['analisis' => 'El elemento debe ser un objeto.']);
                }
                $record = $this->register($actorId, $item);
                $result[$record['estado'] === 'creado' ? 'creados' : 'repetidos'][] = ['indice' => $index, 'uuid_publico' => $record['analisis']->uuid_publico, 'uuid_externo' => $record['analisis']->uuid_externo, 'estado' => $record['analisis']->estado];
            } catch (ValidationException $e) {
                $result['rechazados'][] = ['indice' => $index, 'errores' => $e->errors()];
            } catch (AuthorizationException|HttpException $e) {
                $result['rechazados'][] = ['indice' => $index, 'errores' => ['autorizacion' => ['No tienes acceso a este análisis.']]];
            }
        }

        return $result;
    }

    public function saveProfile(int $actorId, array $input): PerfilCalidad
    {
        Gate::forUser(User::findOrFail($actorId))->authorize('administrar-calidad');
        $rules = ['nombre' => ['required', 'string', 'max:150'], 'activo' => ['required', 'boolean'], 'vigente_desde' => ['required', 'date_format:Y-m-d'], 'vigente_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:vigente_desde'], 'criterios' => ['required', 'array']];
        foreach (ParametrosCalidad::criteria() as $key => $field) {
            $prefix = 'criterios.'.$key;
            $rules[$prefix] = ['required', 'array'];
            $rules[$prefix.'.minimo'] = ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:'.$field[2]];
            $rules[$prefix.'.maximo'] = ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:'.$field[2]];
            $rules[$prefix.'.unidad'] = ['required', Rule::in([$field[1]])];
            $rules[$prefix.'.activo'] = ['required', 'boolean'];
            $rules[$prefix.'.desde'] = ['required', 'date_format:Y-m-d'];
            $rules[$prefix.'.hasta'] = ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$prefix.'.desde'];
        }
        $input = $this->emptyToNull($input);
        $data = Validator::make($input, $rules, ParametrosCalidad::messages() + ['after_or_equal' => 'La fecha final no puede ser anterior al inicio.', 'date_format' => 'Usa una fecha válida (AAAA-MM-DD).', 'array' => 'Los criterios deben ser una lista válida.', 'boolean' => 'Selecciona activo o inactivo.'])->validate();
        foreach (ParametrosCalidad::criteria() as $key => $field) {
            $criterion = $data['criterios'][$key];
            foreach (['minimo', 'maximo'] as $limit) {
                $criterion[$limit] = isset($criterion[$limit]) && $criterion[$limit] !== '' ? bcadd((string) $criterion[$limit], '0', 4) : null;
            }
            if ($criterion['minimo'] !== null && $criterion['maximo'] !== null && bccomp($criterion['minimo'], $criterion['maximo'], 4) > 0) {
                throw ValidationException::withMessages(['criterios.'.$key.'.maximo' => 'El máximo no puede ser menor al mínimo.']);
            }
            $data['criterios'][$key] = $criterion;
        }
        $data['criterios'] = array_intersect_key($data['criterios'], ParametrosCalidad::criteria());

        return $this->users->underAdminLock(function () use ($actorId, $data): PerfilCalidad {
            Gate::forUser(User::findOrFail($actorId))->authorize('administrar-calidad');

            return PerfilCalidad::create($data + ['version' => (int) PerfilCalidad::max('version') + 1, 'creado_por' => $actorId]);
        });
    }

    public function correct(int $actorId, int $id, array $input, string $reason): AnalisisCalidad
    {
        return $this->change($actorId, $id, 'correccion', $reason, function (AnalisisCalidad $analysis) use ($input): void {
            $input = $this->emptyToNull($input);
            $data = Validator::make($input, ParametrosCalidad::rules(false), ParametrosCalidad::messages(), ParametrosCalidad::attributes())->validate();
            $data = $this->normalize($data);
            $snapshot = $analysis->limites_aplicados;
            $analysis->fill($data + $this->evaluate($data, $snapshot ? $snapshot : null));
        });
    }

    public function review(int $actorId, int $id, string $reason): AnalisisCalidad
    {
        return $this->change($actorId, $id, 'revision', $reason, function (AnalisisCalidad $analysis): void {
            $analysis->fill($this->evaluate($analysis->toArray(), $this->profileFor($analysis->muestra_at)));
        });
    }

    public function annul(int $actorId, int $id, string $reason): AnalisisCalidad
    {
        return $this->change($actorId, $id, 'anulacion', $reason, function (AnalisisCalidad $analysis): void {
            $analysis->estado = 'anulado';
        });
    }

    private function change(int $actorId, int $id, string $action, string $reason, \Closure $operation): AnalisisCalidad
    {
        Gate::forUser(User::findOrFail($actorId))->authorize('administrar-calidad');
        Validator::make(['motivo' => trim($reason)], ['motivo' => ['required', 'string', 'max:1000']], ['required' => 'El motivo es obligatorio.', 'max' => 'El motivo no puede superar 1000 caracteres.'])->validate();

        return $this->users->underAdminLock(function () use ($actorId, $id, $action, $reason, $operation): AnalisisCalidad {
            Gate::forUser(User::findOrFail($actorId))->authorize('administrar-calidad');
            $analysis = AnalisisCalidad::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($analysis->estado === 'anulado') {
                throw ValidationException::withMessages(['estado' => 'Un análisis anulado no admite cambios.']);
            }
            $before = $analysis->toArray();
            $operation($analysis);
            $analysis->save();
            app(InvalidarRanking::class)->analysis($analysis, $before['muestra_at']);
            app(GestionarPenalizaciones::class)->capture($analysis, $actorId);
            AuditoriaCalidad::create(['analisis_id' => $id, 'usuario_id' => $actorId, 'accion' => $action, 'anteriores' => $before, 'nuevos' => $analysis->fresh()->toArray(), 'motivo' => trim($reason)]);

            return $analysis;
        });
    }

    private function emptyToNull(array $input): array
    {
        array_walk_recursive($input, function (&$value): void {
            if ($value === '') {
                $value = null;
            }
        });

        return $input;
    }

    private function normalize(array $data): array
    {
        foreach (ParametrosCalidad::CAMPOS as $key => $field) {
            $data[$key] = isset($data[$key]) && $data[$key] !== '' ? bcadd((string) $data[$key], '0', 4) : null;
        }
        $data['densidad_corregida'] ??= $this->density->corregir($data['densidad_medida'], $data['temperatura']);
        $data['muestra_at'] = Carbon::parse($data['muestra_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');

        return $data;
    }

    private function profileFor(mixed $date): ?array
    {
        $day = Carbon::parse($date)->setTimezone(config('app.timezone'))->toDateString();
        $profile = PerfilCalidad::where('vigente_desde', '<=', $day)->where(fn ($q) => $q->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', $day))->orderByDesc('version')->first();

        return $profile?->toArray();
    }

    private function evaluate(array $data, ?array $profile): array
    {
        $warnings = [];
        $day = Carbon::parse($data['muestra_at'])->setTimezone(config('app.timezone'))->toDateString();
        $profileApplies = $profile && $profile['activo'] && $profile['vigente_desde'] <= $day && (empty($profile['vigente_hasta']) || $profile['vigente_hasta'] >= $day);
        $incomplete = ! $profileApplies;
        foreach (ParametrosCalidad::criteria() as $key => $field) {
            $criterion = $profile['criterios'][$key] ?? null;
            if (! $criterion || ! $criterion['activo'] || $criterion['minimo'] === null || $criterion['maximo'] === null || $criterion['desde'] > $day || (! empty($criterion['hasta']) && $criterion['hasta'] < $day) || ($data[$key] ?? null) === null) {
                $incomplete = true;
                $warnings[$key] = 'Sin medición o criterio completo y vigente.';

                continue;
            }
            if (bccomp((string) $data[$key], $criterion['minimo'], 4) < 0 || bccomp((string) $data[$key], $criterion['maximo'], 4) > 0) {
                $warnings[$key] = $field[0].': '.$data[$key].' '.$field[1].' fuera de ['.$criterion['minimo'].', '.$criterion['maximo'].'].';
            }
        }
        if (! $profileApplies) {
            $warnings['perfil'] = 'No existe un perfil activo aplicable.';
        }

        return ['perfil_id' => $profile['id'] ?? null, 'limites_aplicados' => $profile ?? [], 'advertencias' => $warnings, 'estado' => $incomplete ? 'pendiente_revision' : ($warnings ? 'observado' : 'conforme')];
    }
}
