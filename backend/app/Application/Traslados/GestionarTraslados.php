<?php

namespace App\Application\Traslados;

use App\Application\Operacion\AuditarOperacion;
use App\Application\Operacion\ReglasOperativas;
use App\Application\Productores\IdentidadProductor;
use App\Domain\Rutas\RutaRepository;
use App\Domain\Usuarios\UsuarioRepository;
use App\Infrastructure\Operacion\ReglaOperativa;
use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Rutas\RutaAcopio;
use App\Infrastructure\Traslados\SolicitudTraslado;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class GestionarTraslados
{
    public function __construct(private UsuarioRepository $users, private RutaRepository $routes, private AuditarOperacion $audit, private ReglasOperativas $rules, private IdentidadProductor $identity) {}

    public function authorize(int $actor): void
    {
        Gate::forUser(User::findOrFail($actor))->authorize('administrar-traslados');
    }

    public function request(int $actor, array $input): SolicitudTraslado
    {
        $admin = User::findOrFail($actor)->isActiveAdministrator();
        $own = $admin ? null : $this->identity->id($actor);
        $data = Validator::make($input, ['uuid' => ['required', 'uuid'], 'productor_id' => [$admin ? 'required' : 'nullable', 'integer'], 'ruta_solicitada_id' => ['required', 'integer'], 'fecha_efectiva' => ['required', 'date_format:Y-m-d'], 'motivo' => ['required', 'string', 'max:2000']], ['required' => 'Completa :attribute.', 'uuid' => 'UUID inválido.', 'integer' => 'Identificador inválido.', 'date_format' => 'Usa una fecha válida AAAA-MM-DD.', 'max' => 'Motivo demasiado largo.'])->validate();
        $data['motivo'] = $this->audit->reason($data['motivo']);
        if (! $admin && isset($data['productor_id']) && (int) $data['productor_id'] !== $own) {
            abort(403);
        }

        return $this->users->underAdminLock(function () use ($actor, $data, $admin): SolicitudTraslado {
            if ($admin) {
                $this->authorize($actor);
                $producerId = (int) $data['productor_id'];
            } else {
                $producerId = $this->identity->id($actor);
            }
            if ($existing = SolicitudTraslado::where('uuid', $data['uuid'])->first()) {
                abort_unless($existing->productor_id === $producerId, 404);

                return $existing;
            }
            $producer = Productor::whereKey($producerId)->where('estado', true)->lockForUpdate()->first();
            if (! $producer) {
                throw ValidationException::withMessages(['productor_id' => 'Selecciona un productor activo.']);
            }
            $assignment = DB::table('ruta_productor')->where('productor_id', $producerId)->lockForUpdate()->first();
            if (! $assignment) {
                throw ValidationException::withMessages(['ruta' => 'El productor no tiene una ruta actual.']);
            }
            $target = RutaAcopio::whereKey($data['ruta_solicitada_id'])->where('estado', true)->lockForUpdate()->first();
            if (! $target || $target->id === $assignment->ruta_id) {
                throw ValidationException::withMessages(['ruta_solicitada_id' => 'Selecciona otra ruta activa.']);
            }
            if (SolicitudTraslado::where('productor_id', $producerId)->whereIn('estado', ['pendiente', 'aprobada'])->exists()) {
                throw ValidationException::withMessages(['conflicto' => 'El productor ya tiene una solicitud pendiente o aprobada.']);
            }
            $rule = $this->rules->current('traslados');
            $days = $rule['valores']['anticipacion_dias'];
            if ($days === null) {
                throw ValidationException::withMessages(['configuracion' => 'El administrador debe definir la anticipación de traslados.']);
            }
            if (Carbon::parse($data['fecha_efectiva'])->startOfDay()->lt(today()->addDays($days))) {
                throw ValidationException::withMessages(['fecha_efectiva' => 'La fecha efectiva requiere al menos '.$days.' días calendario de anticipación.']);
            }
            $r = SolicitudTraslado::create(['uuid' => $data['uuid'], 'productor_id' => $producerId, 'ruta_actual_id' => $assignment->ruta_id, 'ruta_solicitada_id' => $target->id, 'solicitante_id' => $actor, 'solicitada_at' => now(), 'fecha_efectiva' => $data['fecha_efectiva'], 'motivo' => $data['motivo'], 'regla_aplicada' => $rule, 'estado' => 'pendiente']);
            $this->audit->record('traslados', $r->uuid, 'solicitud', $actor, [], $r->toArray(), $data['motivo']);

            return $r;
        });
    }

    public function decide(int $actor, string $uuid, bool $approve, string $comment): SolicitudTraslado
    {
        $this->authorize($actor);
        $comment = $this->audit->reason($comment);

        return $this->users->underAdminLock(function () use ($actor, $uuid, $approve, $comment): SolicitudTraslado {
            $this->authorize($actor);
            $r = SolicitudTraslado::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $state = $approve ? 'aprobada' : 'rechazada';
            if ($r->estado === $state) {
                return $r;
            } if ($r->estado !== 'pendiente') {
                throw ValidationException::withMessages(['estado' => 'Solo se decide una solicitud pendiente.']);
            } if ($approve) {
                $this->validateAssignment($r);
                if ($r->fecha_efectiva->lt(today())) {
                    throw ValidationException::withMessages(['fecha_efectiva' => 'La fecha efectiva ya pasó. Rechaza y solicita una nueva fecha.']);
                }
            } $before = $r->toArray();
            $r->update(['estado' => $state, 'decidida_por' => $actor, 'decidida_at' => now(), 'comentario' => $comment]);
            $this->audit->record('traslados', $uuid, $state, $actor, $before, $r->toArray(), $comment);

            return $r;
        });
    }

    public function cancel(int $actor, string $uuid, string $reason): SolicitudTraslado
    {
        $reason = $this->audit->reason($reason);

        return $this->users->underAdminLock(function () use ($actor, $uuid, $reason): SolicitudTraslado {
            $admin = User::findOrFail($actor)->isActiveAdministrator();
            $own = $admin ? null : $this->identity->id($actor);
            $r = SolicitudTraslado::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($admin || $r->productor_id === $own, 404);
            if ($r->estado === 'cancelada') {
                return $r;
            } if (! in_array($r->estado, ['pendiente', 'aprobada'], true)) {
                throw ValidationException::withMessages(['estado' => 'Solo se cancelan solicitudes pendientes o aprobadas sin aplicar.']);
            } $before = $r->toArray();
            $r->update(['estado' => 'cancelada']);
            $this->audit->record('traslados', $uuid, 'cancelada', $actor, $before, $r->toArray(), $reason);

            return $r;
        });
    }

    public function apply(int $actor, string $uuid): SolicitudTraslado
    {
        $this->authorize($actor);

        return $this->users->underAdminLock(function () use ($actor, $uuid): SolicitudTraslado {
            $this->authorize($actor);

            return $this->applyLocked($uuid, $actor, false);
        });
    }

    public function applyScheduled(string $uuid): SolicitudTraslado
    {
        return $this->users->underAdminLock(function () use ($uuid): SolicitudTraslado {
            $r = SolicitudTraslado::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if (! $r->decidida_por) {
                throw ValidationException::withMessages(['estado' => 'Falta aprobación administrativa.']);
            }

            return $this->applyLocked($uuid, $r->decidida_por, true);
        });
    }

    private function applyLocked(string $uuid, int $actor, bool $scheduled): SolicitudTraslado
    {
        $r = SolicitudTraslado::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
        if ($r->estado === 'aplicada') {
            return $r;
        }
        if ($r->estado !== 'aprobada' || $r->fecha_efectiva->gt(today())) {
            throw ValidationException::withMessages(['estado' => 'Solo se aplica una solicitud aprobada cuya fecha efectiva haya llegado.']);
        }
        $before = $r->toArray();
        $assignment = $this->validateAssignment($r);
        $order = (int) DB::table('ruta_productor')->where('ruta_id', $r->ruta_solicitada_id)->max('orden') + 1;
        DB::table('ruta_productor')->where('productor_id', $r->productor_id)->update(['ruta_id' => $r->ruta_solicitada_id, 'orden' => $order]);
        $remaining = DB::table('ruta_productor')->where('ruta_id', $r->ruta_actual_id)->orderBy('orden')->pluck('productor_id')->all();
        $this->routes->reorder($r->ruta_actual_id, $remaining);
        DB::table('historial_traslados')->insert(['solicitud_id' => $r->id, 'productor_id' => $r->productor_id, 'ruta_anterior_id' => $r->ruta_actual_id, 'ruta_nueva_id' => $r->ruta_solicitada_id, 'orden_anterior' => $assignment->orden, 'orden_nuevo' => $order, 'usuario_id' => $actor, 'aplicada_at' => now()]);
        $r->update(['estado' => 'aplicada', 'aplicada_at' => now(), 'ultimo_error' => null]);
        $this->audit->record('traslados', $uuid, $scheduled ? 'aplicacion_automatica' : 'aplicada', $actor, $before, $r->toArray(), 'Aplicación de traslado aprobado: '.$r->comentario);

        return $r;
    }

    private function validateAssignment(SolicitudTraslado $r): object
    {
        $producer = Productor::whereKey($r->productor_id)->where('estado', true)->lockForUpdate()->first();
        $routes = RutaAcopio::whereIn('id', [$r->ruta_actual_id, $r->ruta_solicitada_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $assignment = DB::table('ruta_productor')->where('productor_id', $r->productor_id)->lockForUpdate()->first();
        if (! $producer || ! ($routes[$r->ruta_solicitada_id]->estado ?? false) || ! $assignment || $assignment->ruta_id !== $r->ruta_actual_id) {
            throw ValidationException::withMessages(['conflicto' => 'Cambió la asignación o el estado del productor/ruta. Revisa y cancela la solicitud antes de crear otra.']);
        }

        return $assignment;
    }

    public function configuration(int $actor, array $input): array
    {
        $this->authorize($actor);
        $data = Validator::make($input, ['anticipacion_dias' => ['required', 'integer', 'between:1,365'], 'motivo' => ['required', 'string', 'max:2000']], ['required' => 'Completa :attribute.', 'integer' => 'Los días deben ser enteros.', 'between' => 'Configura entre 1 y 365 días calendario.', 'max' => 'Motivo demasiado largo.'])->validate();
        $reason = $this->audit->reason($data['motivo']);

        return $this->users->underAdminLock(function () use ($actor, $data, $reason): array {
            $this->authorize($actor);
            $before = $this->rules->current('traslados');
            ReglaOperativa::create(['clave' => 'traslados', 'version' => $before['version'] + 1, 'valores' => ['anticipacion_dias' => (int) $data['anticipacion_dias']], 'autor_id' => $actor, 'motivo' => $reason]);
            $after = $this->rules->current('traslados');
            $this->audit->record('traslados', 'configuracion', 'configuracion', $actor, $before, $after, $reason);

            return $after;
        });
    }

    public function processDue(): array
    {
        $result = ['aplicadas' => 0, 'conflictos' => 0];
        SolicitudTraslado::where('estado', 'aprobada')->whereDate('fecha_efectiva', '<=', today())->orderBy('id')->chunkById(100, function ($records) use (&$result): void {
            foreach ($records as $r) {
                try {
                    $this->applyScheduled($r->uuid);
                    $result['aplicadas']++;
                } catch (ValidationException $e) {
                    $message = implode(' ', array_merge(...array_values($e->errors())));
                    $this->users->underAdminLock(function () use ($r, $message): void {
                        $fresh = SolicitudTraslado::whereKey($r->id)->lockForUpdate()->firstOrFail();
                        if ($fresh->estado === 'aprobada' && $fresh->ultimo_error !== $message) {
                            $before = $fresh->toArray();
                            $fresh->update(['ultimo_error' => $message]);
                            $this->audit->record('traslados', $fresh->uuid, 'conflicto_automatico', $fresh->decidida_por, $before, $fresh->toArray(), $message);
                        }
                    });
                    $result['conflictos']++;
                }
            }
        });

        return $result;
    }
}
