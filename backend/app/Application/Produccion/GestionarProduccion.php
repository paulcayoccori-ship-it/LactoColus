<?php

namespace App\Application\Produccion;

use App\Application\Inventario\GestionarInventario;
use App\Application\Operacion\AuditarOperacion;
use App\Application\Operacion\ReglasOperativas;
use App\Domain\Usuarios\UsuarioRepository;
use App\Infrastructure\Produccion\AjusteProduccion;
use App\Infrastructure\Produccion\AlertaRendimiento;
use App\Infrastructure\Produccion\LoteProduccion;
use App\Infrastructure\Produccion\UsoRecepcion;
use App\Infrastructure\Recepciones\RecepcionPlanta;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class GestionarProduccion
{
    public const TIPOS = ['paria_fresco', 'paria_pasteurizado'];

    public function __construct(private UsuarioRepository $users, private AuditarOperacion $audit, private ReglasOperativas $rules, private GestionarInventario $inventory) {}

    public function authorize(int $actor): void
    {
        Gate::forUser(User::findOrFail($actor))->authorize('administrar-produccion');
    }

    public static function inputRules(): array
    {
        return ['uuid' => ['required', 'uuid'], 'codigo' => ['required', 'string', 'max:60'], 'producido_at' => ['required', 'date'], 'tipo' => ['required', 'in:paria_fresco,paria_pasteurizado'], 'observaciones' => ['nullable', 'string', 'max:5000'], 'recepciones' => ['required', 'array', 'min:1', 'max:100'], 'recepciones.*.recepcion_id' => ['required', 'integer', 'distinct'], 'recepciones.*.litros' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999.999']];
    }

    public static function messages(): array
    {
        return ['required' => 'El campo :attribute es obligatorio.', 'array' => 'Selecciona una lista válida.', 'min' => 'Agrega al menos una recepción.', 'max' => 'El campo :attribute supera el máximo permitido.', 'in' => 'Selecciona un tipo de queso válido.', 'uuid' => 'El UUID no es válido.', 'numeric' => 'Los litros deben ser numéricos.', 'decimal' => 'Usa hasta tres decimales.', 'gt' => 'Los litros deben ser mayores que cero.', 'integer' => 'Selecciona una recepción válida.', 'distinct' => 'No repitas una recepción.', 'date' => 'La fecha no es válida.'];
    }

    public function create(int $actor, array $input): LoteProduccion
    {
        $this->authorize($actor);
        $uuid = Validator::make($input, ['uuid' => ['required', 'uuid']], self::messages())->validate()['uuid'];
        if ($existing = LoteProduccion::where('uuid', $uuid)->first()) {
            return $existing;
        }
        $data = Validator::make($input, self::inputRules(), self::messages())->validate();

        return $this->users->underAdminLock(function () use ($actor, $data): LoteProduccion {
            $this->authorize($actor);
            if ($existing = LoteProduccion::where('uuid', $data['uuid'])->first()) {
                return $existing;
            }
            $allocations = collect($data['recepciones'])->sortBy('recepcion_id');
            $total = '0.000';
            foreach ($allocations as $allocation) {
                $reception = RecepcionPlanta::whereKey($allocation['recepcion_id'])->lockForUpdate()->first();
                if (! $reception || $reception->resultado === 'anulada') {
                    throw ValidationException::withMessages(['recepciones' => 'Una recepción no existe o está anulada.']);
                }
                $free = bcsub($reception->litros_planta, UsoRecepcion::occupied($reception->id), 3);
                if (bccomp((string) $allocation['litros'], $free, 3) > 0) {
                    throw ValidationException::withMessages(['recepciones' => 'La recepción '.$reception->uuid_publico.' solo tiene '.$free.' litros disponibles.']);
                }
                $total = bcadd($total, (string) $allocation['litros'], 3);
            }
            if (bccomp($total, '999999999.999', 3) > 0) {
                throw ValidationException::withMessages(['recepciones' => 'El total supera la capacidad de registro.']);
            }
            try {
                $lot = LoteProduccion::create(['uuid' => $data['uuid'], 'codigo' => trim($data['codigo']), 'producido_at' => Carbon::parse($data['producido_at'])->setTimezone(config('app.timezone')), 'tipo' => $data['tipo'], 'litros_cuba' => $total, 'moldes' => 0, 'regla_aplicada' => $this->rules->current('rendimiento'), 'responsable_id' => $actor, 'estado' => 'borrador', 'observaciones' => $data['observaciones'] ?? null]);
            } catch (UniqueConstraintViolationException $e) {
                throw ValidationException::withMessages(['codigo' => 'El código de lote ya está registrado.']);
            }
            foreach ($allocations as $allocation) {
                UsoRecepcion::create(['lote_id' => $lot->id, 'recepcion_id' => $allocation['recepcion_id'], 'litros' => bcadd((string) $allocation['litros'], '0', 3), 'estado' => 'reservado']);
            }
            $this->audit->record('produccion', $lot->uuid, 'creacion', $actor, [], $lot->toArray(), 'Registro del lote y reserva de litros');

            return $lot;
        });
    }

    public function start(int $actor, string $uuid): LoteProduccion
    {
        return $this->locked($actor, $uuid, function (LoteProduccion $lot) use ($actor): LoteProduccion {
            if ($lot->estado === 'en_proceso') {
                return $lot;
            }
            $this->state($lot, ['borrador']);
            $before = $lot->toArray();
            $this->lockReceptions($lot);
            $lot->usos()->update(['estado' => 'consumido']);
            $lot->update(['estado' => 'en_proceso']);
            $this->audit->record('produccion', $uuid = $lot->uuid, 'inicio', $actor, $before, $lot->toArray(), 'Inicio de producción; litros consumidos');

            return $lot;
        });
    }

    public function finish(int $actor, string $uuid, mixed $molds): LoteProduccion
    {
        $this->authorize($actor);
        $validated = Validator::make(['moldes' => $molds], ['moldes' => ['required', 'integer', 'min:0', 'max:10000000']], ['min' => 'Los moldes no pueden ser negativos.', 'max' => 'Cantidad de moldes fuera de capacidad.', 'integer' => 'La cantidad de moldes debe ser entera.'])->validate();
        $molds = (int) $validated['moldes'];

        return $this->locked($actor, $uuid, function (LoteProduccion $lot) use ($actor, $molds): LoteProduccion {
            if ($lot->estado === 'finalizado') {
                if ($lot->moldes !== $molds) {
                    throw ValidationException::withMessages(['moldes' => 'El lote está finalizado. Utiliza un ajuste auditado.']);
                }

                return $lot;
            }
            $this->state($lot, ['en_proceso']);
            $before = $lot->toArray();
            $yield = $this->yield($molds, $lot->litros_cuba);
            $lot->update(['moldes' => $molds, 'rendimiento' => $yield, 'estado' => 'finalizado']);
            $this->refreshAlert($lot, $molds);
            $this->inventory->production($actor, $lot);
            $this->audit->record('produccion', $lot->uuid, 'finalizacion', $actor, $before, $lot->toArray(), 'Finalización de producción');

            return $lot;
        });
    }

    public function adjust(int $actor, string $uuid, array $input): LoteProduccion
    {
        $this->authorize($actor);
        $data = Validator::make($input, ['uuid' => ['required', 'uuid'], 'delta_moldes' => ['required', 'integer', 'not_in:0', 'between:-10000000,10000000'], 'motivo' => ['required', 'string', 'max:2000']], ['required' => 'El campo :attribute es obligatorio.', 'uuid' => 'El UUID del ajuste no es válido.', 'integer' => 'La variación debe ser un número entero de moldes.', 'not_in' => 'La variación no puede ser cero.', 'between' => 'La variación está fuera de capacidad.', 'max' => 'El motivo es demasiado largo.'])->validate();
        $data['motivo'] = $this->audit->reason($data['motivo']);

        return $this->locked($actor, $uuid, function (LoteProduccion $lot) use ($actor, $data): LoteProduccion {
            if ($existing = AjusteProduccion::where('uuid', $data['uuid'])->first()) {
                if ($existing->lote_id !== $lot->id) {
                    throw ValidationException::withMessages(['uuid' => 'El UUID ya corresponde a otro lote.']);
                }

                return $lot;
            }
            $this->state($lot, ['finalizado']);
            $before = $this->effectiveMolds($lot);
            $after = $before + (int) $data['delta_moldes'];
            if ($after < 0 || $after > 10000000) {
                throw ValidationException::withMessages(['delta_moldes' => 'El total ajustado no puede ser negativo ni superar la capacidad.']);
            }
            $this->inventory->productionAdjustment($actor, $lot, $data['uuid'], (int) $data['delta_moldes'], $data['motivo']);
            AjusteProduccion::create(['uuid' => $data['uuid'], 'lote_id' => $lot->id, 'delta_moldes' => $data['delta_moldes'], 'usuario_id' => $actor, 'motivo' => $data['motivo']]);
            $this->refreshAlert($lot, $after);
            $this->audit->record('produccion', $lot->uuid, 'ajuste', $actor, ['moldes_efectivos' => $before], ['moldes_efectivos' => $after, 'uuid_ajuste' => $data['uuid']], $data['motivo']);

            return $lot;
        });
    }

    public function annul(int $actor, string $uuid, string $reason): LoteProduccion
    {
        $this->authorize($actor);
        $reason = $this->audit->reason($reason);

        return $this->locked($actor, $uuid, function (LoteProduccion $lot) use ($actor, $reason): LoteProduccion {
            if ($lot->estado === 'anulado') {
                return $lot;
            }
            $before = $lot->toArray();
            $this->lockReceptions($lot);
            $this->inventory->annulProduction($actor, $lot, $reason);
            if ($lot->estado === 'borrador') {
                $lot->usos()->update(['estado' => 'liberado']);
            }
            $lot->update(['estado' => 'anulado']);
            $lot->alerta()->update(['estado' => 'resuelta']);
            $this->audit->record('produccion', $lot->uuid, 'anulacion', $actor, $before, $lot->toArray(), $reason);

            return $lot;
        });
    }

    public function correctDraft(int $actor, string $uuid, array $input, string $reason): LoteProduccion
    {
        $this->authorize($actor);
        $reason = $this->audit->reason($reason);
        $rules = self::inputRules();
        unset($rules['uuid'],$rules['recepciones'],$rules['recepciones.*.recepcion_id'],$rules['recepciones.*.litros']);
        $data = Validator::make($input, $rules, self::messages())->validate();

        return $this->locked($actor, $uuid, function (LoteProduccion $lot) use ($actor, $data, $reason): LoteProduccion {
            $this->state($lot, ['borrador']);
            $before = $lot->toArray();
            if (LoteProduccion::where('codigo', trim($data['codigo']))->whereKeyNot($lot->id)->exists()) {
                throw ValidationException::withMessages(['codigo' => 'El código ya está registrado.']);
            }
            $data['producido_at'] = Carbon::parse($data['producido_at'])->setTimezone(config('app.timezone'));
            $lot->update($data);
            $this->audit->record('produccion', $lot->uuid, 'correccion', $actor, $before, $lot->toArray(), $reason);

            return $lot;
        });
    }

    public function effectiveMolds(LoteProduccion $lot): int
    {
        return $lot->moldes + (int) $lot->ajustes()->sum('delta_moldes');
    }

    public function yield(int $molds, string $liters): string
    {
        return bcdiv(bcmul((string) $molds, '100', 6), $liters, 6);
    }

    private function refreshAlert(LoteProduccion $lot, int $molds): void
    {
        $yield = $this->yield($molds, $lot->litros_cuba);
        $range = $lot->regla_aplicada['valores'];
        // La decisión se compara multiplicando, sin redondear el cociente del rendimiento.
        $outside = bccomp(bcmul((string) $molds, '100', 6), bcmul($range['minimo'], $lot->litros_cuba, 6), 6) < 0 || bccomp(bcmul((string) $molds, '100', 6), bcmul($range['maximo'], $lot->litros_cuba, 6), 6) > 0;
        if ($outside || $lot->alerta()->exists()) {
            AlertaRendimiento::updateOrCreate(['lote_id' => $lot->id], ['rendimiento' => $yield, 'estado' => $outside ? 'pendiente' : 'resuelta', 'regla_aplicada' => $lot->regla_aplicada]);
        }
    }

    private function locked(int $actor, string $uuid, \Closure $operation): LoteProduccion
    {
        $this->authorize($actor);

        return $this->users->underAdminLock(function () use ($actor, $uuid, $operation): LoteProduccion {
            $this->authorize($actor);

            return $operation(LoteProduccion::where('uuid', $uuid)->lockForUpdate()->firstOrFail());
        });
    }

    private function state(LoteProduccion $lot, array $states): void
    {
        if (! in_array($lot->estado, $states, true)) {
            throw ValidationException::withMessages(['estado' => 'La operación no está permitida en el estado '.$lot->estado.'.']);
        }
    }

    private function lockReceptions(LoteProduccion $lot): void
    {
        RecepcionPlanta::whereIn('id', $lot->usos()->select('recepcion_id'))->orderBy('id')->lockForUpdate()->get();
    }
}
