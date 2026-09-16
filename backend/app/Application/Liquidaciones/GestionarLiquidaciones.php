<?php

namespace App\Application\Liquidaciones;

use App\Application\Operacion\AuditarOperacion;
use App\Domain\Usuarios\UsuarioRepository;
use App\Infrastructure\Liquidaciones\AjusteLiquidacion;
use App\Infrastructure\Liquidaciones\Liquidacion;
use App\Infrastructure\Liquidaciones\PagoLiquidacion;
use App\Infrastructure\Liquidaciones\PeriodoLiquidacion;
use App\Infrastructure\Ventas\Venta;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class GestionarLiquidaciones
{
    public function __construct(private UsuarioRepository $users, private AuditarOperacion $audit) {}

    public function totals(Liquidacion $liquidacion): array
    {
        $bonuses = '0.00';
        $adjustments = '0.00';
        foreach ($liquidacion->ajustes as $adjustment) {
            if ($adjustment->estado !== 'aprobado') {
                continue;
            }
            if ($adjustment->tipo === 'bono') {
                $bonuses = bcadd($bonuses, $adjustment->importe, 2);
            } else {
                $adjustments = bcadd($adjustments, $adjustment->importe, 2);
            }
        }
        $lostBonuses = $liquidacion->perdida_liquidacion && ($liquidacion->regla_aplicada['valores']['perdida_incluye_bonos'] ?? false) ? $bonuses : '0.00';

        return ['bonos' => $bonuses, 'bonos_retenidos' => $lostBonuses, 'ajustes' => $adjustments, 'total' => bcsub(bcadd(bcadd($liquidacion->total_base, $bonuses, 2), $adjustments, 2), $lostBonuses, 2)];
    }

    public function approve(int $actor, string $uuid, string $reason): PeriodoLiquidacion
    {
        return $this->users->underAdminLock(function () use ($actor, $uuid, $reason): PeriodoLiquidacion {
            $this->authorize($actor, true);
            $reason = $this->audit->reason($reason);
            $period = PeriodoLiquidacion::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if (in_array($period->estado, ['aprobado', 'pagado'], true)) {
                return $period;
            }
            if ($period->estado !== 'calculado') {
                $this->fail('Solo se aprueba un periodo calculado.');
            }
            $liquidations = $period->liquidaciones()->with('ajustes')->lockForUpdate()->get();
            if ($liquidations->isEmpty()) {
                $this->fail('El periodo no contiene liquidaciones para aprobar.');
            }
            foreach ($liquidations as $liquidation) {
                $this->payable($liquidation);
            }
            $before = $period->toArray();
            $period->liquidaciones()->update(['estado' => 'aprobada']);
            $period->update(['estado' => 'aprobado', 'aprobado_por' => $actor, 'aprobado_at' => now()]);
            $this->audit->record('liquidaciones', $uuid, 'aprobacion', $actor, $before, $period->toArray(), $reason);

            return $period;
        });
    }

    public function adjustment(int $actor, string $uuid, array $input): AjusteLiquidacion
    {
        $this->authorize($actor);
        $data = Validator::make($input, ['uuid' => ['required', 'uuid'], 'tipo' => ['required', 'in:bono,ajuste'], 'importe' => ['required', 'regex:/^-?\d{1,12}(\.\d{1,2})?$/'], 'origen_uuid' => ['nullable', 'uuid'], 'motivo' => ['required', 'string', 'max:2000']])->validate();
        $reason = $this->audit->reason($data['motivo']);

        return $this->users->underAdminLock(function () use ($actor, $uuid, $data, $reason): AjusteLiquidacion {
            $this->authorize($actor);
            $liquidation = Liquidacion::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $existing = AjusteLiquidacion::where('uuid', $data['uuid'])->first();
            if ($existing) {
                if ($existing->liquidacion_id !== $liquidation->id) {
                    $this->fail('El UUID de ajuste ya pertenece a otra liquidación.');
                }

                return $existing;
            }
            if (! in_array($liquidation->estado, ['calculada', 'aprobada'], true)) {
                $this->fail('Una liquidación pagada o anulada no admite cambios. Registra el ajuste en un periodo posterior.');
            }
            if (bccomp($data['importe'], '0', 2) === 0 || ($data['tipo'] === 'bono' && bccomp($data['importe'], '0', 2) < 0)) {
                $this->fail('El importe debe ser distinto de cero y los bonos deben ser positivos.');
            }
            $origin = null;
            if (! empty($data['origen_uuid'])) {
                $origin = Liquidacion::where('uuid', $data['origen_uuid'])->with('periodo')->firstOrFail();
                if ($origin->productor_id !== $liquidation->productor_id || $origin->estado !== 'pagada' || ! $origin->periodo->hasta->lt($liquidation->periodo->desde)) {
                    $this->fail('El origen debe ser una liquidación pagada anterior del mismo productor.');
                }
            }
            $adjustment = AjusteLiquidacion::create(['uuid' => $data['uuid'], 'liquidacion_id' => $liquidation->id, 'origen_liquidacion_id' => $origin?->id, 'tipo' => $data['tipo'], 'importe' => $data['importe'], 'usuario_id' => $actor, 'motivo' => $reason]);
            $this->audit->record('liquidaciones', $uuid, 'solicitud_ajuste', $actor, [], $adjustment->toArray(), $reason);

            return $adjustment;
        });
    }

    public function decideAdjustment(int $actor, string $uuid, array $input): AjusteLiquidacion
    {
        $this->authorize($actor, true);
        $data = Validator::make($input, ['decision' => ['required', 'in:aprobado,rechazado'], 'motivo' => ['required', 'string', 'max:2000']])->validate();
        $reason = $this->audit->reason($data['motivo']);

        return $this->users->underAdminLock(function () use ($actor, $uuid, $data, $reason): AjusteLiquidacion {
            $this->authorize($actor, true);
            $adjustment = AjusteLiquidacion::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $liquidation = Liquidacion::whereKey($adjustment->liquidacion_id)->lockForUpdate()->firstOrFail();
            if ($adjustment->estado !== 'pendiente') {
                if ($adjustment->estado === $data['decision']) {
                    return $adjustment;
                } $this->fail('El ajuste ya tiene una decisión registrada.');
            }
            if (! in_array($liquidation->estado, ['calculada', 'aprobada'], true)) {
                $this->fail('No se puede decidir un ajuste sobre una liquidación pagada o anulada.');
            }
            $before = $adjustment->toArray();
            $adjustment->update(['estado' => $data['decision'], 'decidido_por' => $actor, 'decidido_at' => now(), 'comentario' => $reason]);
            $this->audit->record('liquidaciones', $liquidation->uuid, 'decision_ajuste', $actor, $before, $adjustment->toArray(), $reason);

            return $adjustment;
        });
    }

    public function pay(int $actor, string $uuid, array $input): PagoLiquidacion
    {
        $this->authorize($actor);
        $data = Validator::make($input, ['uuid_externo' => ['required', 'uuid'], 'metodo' => ['required', 'string', 'max:80'], 'pagado_at' => ['required', 'date', 'before_or_equal:now']])->validate();
        if (trim($data['metodo']) === '') {
            $this->fail('Indica el método de pago.');
        }

        return $this->users->underAdminLock(function () use ($actor, $uuid, $data): PagoLiquidacion {
            $this->authorize($actor);
            $liquidation = Liquidacion::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $existing = PagoLiquidacion::where('uuid_externo', $data['uuid_externo'])->first();
            if ($existing) {
                if ($existing->liquidacion_id !== $liquidation->id) {
                    $this->fail('El identificador de pago pertenece a otra liquidación.');
                }

                return $existing;
            }
            if ($liquidation->estado !== 'aprobada' || $liquidation->periodo->estado !== 'aprobado') {
                $this->fail('Solo se paga una liquidación aprobada y todavía pendiente de pago.');
            }
            if (Carbon::parse($data['pagado_at'])->lt($liquidation->periodo->aprobado_at)) {
                $this->fail('La fecha del pago no puede ser anterior a la aprobación.');
            }
            $totals = $this->payable($liquidation);
            $before = $liquidation->toArray();
            $payment = PagoLiquidacion::create(['uuid_externo' => $data['uuid_externo'], 'liquidacion_id' => $liquidation->id, 'importe' => $totals['total'], 'metodo' => trim($data['metodo']), 'pagado_at' => $data['pagado_at'], 'usuario_id' => $actor, 'snapshot' => ['liquidacion' => $before, 'totales' => $totals, 'ajustes' => $liquidation->ajustes->toArray()]]);
            $liquidation->update(['estado' => 'pagada']);
            $sales = DB::table('periodo_ventas')->where('periodo_id', $liquidation->periodo_id)->where('productor_id', $liquidation->productor_id)->where('vigente', true)->pluck('venta_id');
            foreach (Venta::whereIn('id', $sales)->lockForUpdate()->get() as $sale) {
                $old = $sale->toArray();
                $sale->update(['estado' => 'pagada', 'metodo_pago' => 'Liquidación semanal', 'pagada_at' => $data['pagado_at']]);
                $this->audit->record('ventas', $sale->uuid, 'pago_liquidacion', $actor, $old, $sale->toArray(), 'Descuento en liquidación '.$uuid);
            }
            if (! Liquidacion::where('periodo_id', $liquidation->periodo_id)->where('estado', '!=', 'pagada')->exists()) {
                $liquidation->periodo->update(['estado' => 'pagado']);
            }
            $this->audit->record('liquidaciones', $uuid, 'pago', $actor, $before, $payment->toArray(), 'Pago registrado con identificador '.$data['uuid_externo']);

            return $payment;
        });
    }

    private function payable(Liquidacion $liquidation): array
    {
        if ($liquidation->ajustes->contains('estado', 'pendiente')) {
            $this->fail('Resuelve los ajustes pendientes antes de aprobar o pagar.');
        }
        $totals = $this->totals($liquidation);
        if (bccomp($totals['total'], '0', 2) < 0 || bccomp($totals['total'], '999999999999.99', 2) > 0) {
            $this->fail('El total no es pagable. Requiere un ajuste autorizado antes de continuar.');
        }

        return $totals;
    }

    private function authorize(int $actor, bool $admin = false): void
    {
        Gate::forUser(User::findOrFail($actor))->authorize($admin ? 'administrar-liquidaciones' : 'operar-liquidaciones');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['liquidacion' => $message]);
    }
}
