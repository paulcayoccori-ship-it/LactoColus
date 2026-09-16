<?php

namespace App\Application\Penalizaciones;

use App\Application\Operacion\AuditarOperacion;
use App\Application\Operacion\ReglasOperativas;
use App\Domain\Usuarios\UsuarioRepository;
use App\Infrastructure\Calidad\AnalisisCalidad;
use App\Infrastructure\Operacion\ReglaOperativa;
use App\Infrastructure\Penalizaciones\AsistenciaTecnica;
use App\Infrastructure\Penalizaciones\SancionCalidad;
use App\Infrastructure\Productores\Productor;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class GestionarPenalizaciones
{
    public function __construct(private UsuarioRepository $users, private AuditarOperacion $audit, private ReglasOperativas $rules, private InvalidarPenalizaciones $invalidator) {}

    public function authorize(int $actor): void
    {
        Gate::forUser(User::findOrFail($actor))->authorize('administrar-penalizaciones');
    }

    public function configure(int $actor, array $input): array
    {
        $this->authorize($actor);
        $data = Validator::make($input, ['activo' => ['required', 'boolean'], 'tarifa_primera' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999.99'], 'tarifa_grave' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999.99'], 'alcance_tarifa' => ['required', 'in:entrega,dia,semana'], 'unidad_falta' => ['required', 'in:analisis,dia'], 'ventana_dias' => ['present', 'nullable', 'integer', 'between:1,36500'], 'motivo' => ['required', 'string', 'max:2000']], ['required' => 'Completa :attribute.', 'present' => 'Define la ventana de reincidencia o déjala nula para todo el historial.', 'numeric' => 'La tarifa debe ser numérica.', 'decimal' => 'Usa hasta dos decimales.', 'gt' => 'La tarifa debe ser positiva.', 'max' => 'Valor fuera de capacidad.', 'integer' => 'Los días deben ser enteros.', 'between' => 'Ventana fuera de capacidad.', 'boolean' => 'Estado inválido.', 'in' => 'Selecciona una opción válida.'])->validate();
        $reason = $this->audit->reason($data['motivo']);
        unset($data['motivo']);
        foreach (['tarifa_primera', 'tarifa_grave'] as $key) {
            $data[$key] = bcadd((string) $data[$key], '0', 2);
        }

        return $this->users->underAdminLock(function () use ($actor, $data, $reason): array {
            $this->authorize($actor);
            $before = $this->rules->current('penalizaciones');
            ReglaOperativa::create(['clave' => 'penalizaciones', 'version' => $before['version'] + 1, 'valores' => $data, 'autor_id' => $actor, 'motivo' => $reason]);
            $after = $this->rules->current('penalizaciones');
            $this->audit->record('penalizaciones', 'reglas', 'configuracion', $actor, $before, $after, $reason);

            return $after;
        });
    }

    public function capture(AnalisisCalidad $analysis, int $actor): void
    {
        Gate::forUser(User::findOrFail($actor))->authorize('operar-calidad');
        $this->users->underAdminLock(function () use ($analysis, $actor): void {
            Gate::forUser(User::findOrFail($actor))->authorize('operar-calidad');
            $this->invalidator->analysis($analysis, $actor);
            $rule = $this->rules->current('penalizaciones');
            if (! SancionCalidad::where('analisis_id', $analysis->id)->exists()) {
                $this->evaluateOne($analysis, $actor, $rule, 'Detección inicial pendiente de revisión administrativa');
            }
            if (! AsistenciaTecnica::where('analisis_id', $analysis->id)->exists()) {
                $this->assistanceFor($analysis, $actor, 'Detección inicial de acidez');
            }
        });
    }

    public function recalculate(int $actor, int $producerId, string $reason): void
    {
        $this->authorize($actor);
        $reason = $this->audit->reason($reason);
        $this->users->underAdminLock(function () use ($actor, $producerId, $reason): void {
            $this->authorize($actor);
            Productor::withTrashed()->whereKey($producerId)->lockForUpdate()->firstOrFail();
            $rule = $this->rules->current('penalizaciones');
            AnalisisCalidad::where('productor_id', $producerId)->orderBy('muestra_at')->orderBy('id')->lockForUpdate()->get()->each(function ($analysis) use ($actor, $rule, $reason): void {
                $this->evaluateOne($analysis, $actor, $rule, $reason);
                $this->assistanceFor($analysis, $actor, $reason);
            });
        });
    }

    private function evaluateOne(AnalisisCalidad $a, int $actor, array $rule, string $reason): void
    {
        $s = SancionCalidad::where('analisis_id', $a->id)->lockForUpdate()->first();
        if ($a->estado === 'anulado' || $a->agua_anadida === null || bccomp($a->agua_anadida, '0', 4) <= 0) {
            if ($s && ($s->estado !== 'anulada' || $s->requiere_revision)) {
                $before = $s->toArray();
                $s->update(['estado' => 'anulada', 'requiere_revision' => false]);
                $this->audit->record('penalizaciones', $s->uuid, 'anulacion_por_fuente', $actor, $before, $s->toArray(), $reason);
            }

            return;
        }
        $values = $rule['valores'];
        $configured = ($values['activo'] ?? false) && isset($values['tarifa_primera'],$values['tarifa_grave'],$values['unidad_falta'],$values['alcance_tarifa']);
        $prior = AnalisisCalidad::where('productor_id', $a->productor_id)->where('estado', '!=', 'anulado')->where('agua_anadida', '>', 0)->where(fn ($q) => $q->where('muestra_at', '<', $a->muestra_at)->orWhere(fn ($q) => $q->where('muestra_at', $a->muestra_at)->where('id', '<', $a->id)));
        if (! empty($values['ventana_dias'])) {
            $prior->where('muestra_at', '>=', $a->muestra_at->copy()->subDays((int) $values['ventana_dias']));
        }
        if (($values['unidad_falta'] ?? 'analisis') === 'dia') {
            $prior->whereDate('muestra_at', '<', $a->muestra_at->toDateString());
            $number = $prior->get(['muestra_at'])->map(fn ($r) => $r->muestra_at->toDateString())->unique()->count() + 1;
        } else {
            $number = $prior->count() + 1;
        }
        $severe = bccomp($a->agua_anadida, '5', 4) >= 0;
        $type = $severe ? 'grave' : ($number >= 2 ? 'reincidencia' : 'amonestacion');
        $loss = $type === 'reincidencia';
        $expel = $severe || $loss;
        $source = $a->only(['uuid_publico', 'productor_id', 'entrega_id', 'jornada_id', 'muestra_at', 'agua_anadida', 'estado']);
        $fingerprint = hash('sha256', json_encode([$source, $number, $rule], JSON_THROW_ON_ERROR));
        if ($s && $s->huella === $fingerprint) {
            if ($s->requiere_revision) {
                $before = $s->toArray();
                $s->update(['requiere_revision' => false]);
                $this->audit->record('penalizaciones', $s->uuid, 'revalidacion', $actor, $before, $s->toArray(), $reason);
            }

            return;
        }
        $before = $s?->toArray() ?? [];
        $s ??= new SancionCalidad(['uuid' => (string) Str::uuid(), 'analisis_id' => $a->id, 'productor_id' => $a->productor_id]);
        $s->fill(['tipo' => $type, 'estado' => $configured ? 'pendiente' : 'pendiente_configuracion', 'numero_falta' => $number, 'agua_anadida' => $a->agua_anadida, 'tarifa_penalizada' => $configured && ! $loss ? ($severe ? $values['tarifa_grave'] : $values['tarifa_primera']) : null, 'regla_aplicada' => $rule, 'fuente_snapshot' => $source, 'huella' => $fingerprint, 'requiere_revision' => false, 'propuesta_perdida' => $loss, 'propuesta_expulsion' => $expel, 'decision_perdida' => $loss ? 'pendiente' : 'no_aplica', 'decision_expulsion' => $expel ? 'pendiente' : 'no_aplica', 'decidida_por' => null, 'decidida_at' => null, 'comentario' => null])->save();
        $this->audit->record('penalizaciones', $s->uuid, $before ? 'recalculo' : 'deteccion', $actor, $before, $s->toArray(), $reason);
    }

    public function decide(int $actor, string $uuid, array $input): SancionCalidad
    {
        $this->authorize($actor);
        $reason = $this->audit->reason((string) ($input['motivo'] ?? ''));

        return $this->users->underAdminLock(function () use ($actor, $uuid, $input, $reason): SancionCalidad {
            $this->authorize($actor);
            $s = SancionCalidad::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $data = Validator::make($input, ['decision' => ['required', 'in:aprobada,rechazada'], 'decision_perdida' => [$s->propuesta_perdida ? 'required' : 'nullable', 'in:aprobada,rechazada'], 'decision_expulsion' => [$s->propuesta_expulsion ? 'required' : 'nullable', 'in:aprobada,rechazada']], ['required' => 'Decide expresamente cada propuesta.', 'in' => 'Decisión inválida.'])->validate();
            if ($s->estado === $data['decision'] && ! $s->requiere_revision) {
                return $s;
            }
            if ($s->requiere_revision || $s->estado !== 'pendiente') {
                throw ValidationException::withMessages(['estado' => 'Recalcula y revisa una sanción pendiente con configuración aprobada antes de decidir.']);
            }
            $analysis = AnalisisCalidad::whereKey($s->analisis_id)->lockForUpdate()->firstOrFail();
            if ($analysis->estado === 'anulado') {
                throw ValidationException::withMessages(['analisis' => 'El análisis está anulado. Recalcula las sanciones.']);
            }
            if ($data['decision'] === 'aprobada' && $s->tarifa_penalizada !== null && $s->regla_aplicada['valores']['alcance_tarifa'] === 'entrega' && ! $analysis->entrega_id) {
                throw ValidationException::withMessages(['entrega' => 'La tarifa exige una entrega vinculada. No se puede aplicar a litros indeterminados.']);
            }
            $before = $s->toArray();
            $s->update(['estado' => $data['decision'], 'decision_perdida' => $s->propuesta_perdida ? ($data['decision'] === 'rechazada' ? 'rechazada' : $data['decision_perdida']) : 'no_aplica', 'decision_expulsion' => $s->propuesta_expulsion ? ($data['decision'] === 'rechazada' ? 'rechazada' : $data['decision_expulsion']) : 'no_aplica', 'decidida_por' => $actor, 'decidida_at' => now(), 'comentario' => $reason]);
            $this->audit->record('penalizaciones', $uuid, 'decision', $actor, $before, $s->toArray(), $reason);

            return $s;
        });
    }

    public function annul(int $actor, string $uuid, string $reason): void
    {
        $this->authorize($actor);
        $reason = $this->audit->reason($reason);
        $this->users->underAdminLock(function () use ($actor, $uuid, $reason): void {
            $this->authorize($actor);
            $s = SancionCalidad::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($s->estado === 'anulada') {
                return;
            } $before = $s->toArray();
            $s->update(['estado' => 'anulada', 'requiere_revision' => false]);
            $this->audit->record('penalizaciones', $uuid, 'anulacion', $actor, $before, $s->toArray(), $reason);
        });
    }

    private function assistanceFor(AnalisisCalidad $a, int $actor, string $reason): void
    {
        $profile = $a->limites_aplicados;
        $day = $a->muestra_at->toDateString();
        $criterion = $profile['criterios']['acidez'] ?? null;
        $valid = ($profile['activo'] ?? false) && ($profile['vigente_desde'] ?? '9999') <= $day && (empty($profile['vigente_hasta']) || $profile['vigente_hasta'] >= $day) && ($criterion['desde'] ?? '9999') <= $day && (empty($criterion['hasta']) || $criterion['hasta'] >= $day) && $a->estado !== 'anulado' && $a->acidez !== null && $criterion && ($criterion['activo'] ?? false) && isset($criterion['minimo'],$criterion['maximo']) && (bccomp($a->acidez, (string) $criterion['minimo'], 4) < 0 || bccomp($a->acidez, (string) $criterion['maximo'], 4) > 0);
        $record = AsistenciaTecnica::where('analisis_id', $a->id)->lockForUpdate()->first();
        if (! $valid && ! $record) {
            return;
        } $before = $record?->toArray() ?? [];
        $record ??= new AsistenciaTecnica(['uuid' => (string) Str::uuid(), 'analisis_id' => $a->id, 'productor_id' => $a->productor_id, 'estado' => 'pendiente']);
        $record->fill(['criterio_aplicado' => $criterion ?? [], 'fuente_vigente' => (bool) $valid, 'requiere_revision' => false]);
        if (! $valid && in_array($record->estado, ['pendiente', 'programada'], true)) {
            $record->estado = 'cancelada';
        } if ($record->isDirty()) {
            $record->save();
            $this->audit->record('penalizaciones', $record->uuid, 'asistencia_evaluacion', $actor, $before, $record->toArray(), $reason);
        }
    }

    public function assistance(int $actor, string $uuid, array $input): AsistenciaTecnica
    {
        $this->authorize($actor);
        $reason = $this->audit->reason((string) ($input['motivo'] ?? ''));
        $data = Validator::make($input, ['estado' => ['required', 'in:programada,realizada,cancelada'], 'responsable_id' => ['required_unless:estado,cancelada', 'nullable', 'integer'], 'fecha_at' => ['required_unless:estado,cancelada', 'nullable', 'date'], 'observaciones' => ['required', 'string', 'max:5000']], ['required' => 'Completa :attribute.', 'required_unless' => 'Completa :attribute para programar o realizar.', 'in' => 'Estado inválido.', 'integer' => 'Responsable inválido.', 'date' => 'Fecha inválida.', 'max' => 'Observaciones demasiado largas.'])->validate();

        return $this->users->underAdminLock(function () use ($actor, $uuid, $data, $reason): AsistenciaTecnica {
            $this->authorize($actor);
            $r = AsistenciaTecnica::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($data['estado'] !== 'cancelada' && (! $r->fuente_vigente || $r->requiere_revision)) {
                throw ValidationException::withMessages(['fuente' => 'Revisa la fuente antes de programar o realizar asistencia.']);
            } if ($data['estado'] !== 'cancelada' && ! User::whereKey($data['responsable_id'])->where('active', true)->exists()) {
                throw ValidationException::withMessages(['responsable_id' => 'Selecciona un responsable activo.']);
            } $date = empty($data['fecha_at']) ? null : Carbon::parse($data['fecha_at'])->setTimezone(config('app.timezone'));
            if (($data['estado'] === 'programada' && $date->lt(now())) || ($data['estado'] === 'realizada' && $date->gt(now()))) {
                throw ValidationException::withMessages(['fecha_at' => 'La fecha no corresponde al estado solicitado.']);
            } $before = $r->toArray();
            $r->fill($data);
            $r->fecha_at = $date;
            $r->save();
            $this->audit->record('penalizaciones', $uuid, 'asistencia_'.$data['estado'], $actor, $before, $r->toArray(), $reason);

            return $r;
        });
    }
}
