<?php

namespace App\Application\Liquidaciones;

use App\Application\Operacion\AuditarOperacion;
use App\Domain\Usuarios\UsuarioRepository;
use App\Infrastructure\Liquidaciones\Liquidacion;
use App\Infrastructure\Liquidaciones\PeriodoLiquidacion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CalcularLiquidaciones
{
    public function __construct(private UsuarioRepository $users, private AuditarOperacion $audit) {}

    public function calculate(int $actor, string $uuid, string $reason): PeriodoLiquidacion
    {
        Gate::forUser(User::findOrFail($actor))->authorize('operar-liquidaciones');
        $reason = $this->audit->reason($reason);

        return $this->users->underAdminLock(function () use ($actor, $uuid, $reason): PeriodoLiquidacion {
            Gate::forUser(User::findOrFail($actor))->authorize('operar-liquidaciones');
            $p = PeriodoLiquidacion::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($p->estado === 'calculado') {
                return $p;
            } if ($p->estado !== 'cerrado') {
                throw ValidationException::withMessages(['periodo' => 'Solo se calcula un periodo cerrado. Los aprobados se corrigen con ajustes auditados.']);
            }
            $sources = [];
            foreach (['entregas', 'sanciones', 'ventas'] as $kind) {
                $sources[$kind] = DB::table('periodo_'.$kind)->where('periodo_id', $p->id)->orderBy('id')->get()->groupBy('productor_id');
            }
            $rule = $p->regla_aplicada['valores'];
            $price = $rule['precio_litro'];
            $before = $p->toArray();
            foreach (DB::table('periodo_productores')->where('periodo_id', $p->id)->orderBy('productor_id')->get() as $producer) {
                $daily = [];
                for ($d = $p->desde->copy(); $d->lte($p->hasta); $d->addDay()) {
                    $daily[$d->toDateString()] = '0.000';
                }
                $sanctions = collect($sources['sanciones'][$producer->productor_id] ?? [])->map(fn ($s) => json_decode($s->snapshot, true, 512, JSON_THROW_ON_ERROR));
                $loss = $sanctions->contains(fn ($s) => $s['decision_perdida'] === 'aprobada');
                if ($loss && $rule['perdida_incluye_bonos'] === null) {
                    throw ValidationException::withMessages(['reglas' => 'Define y aprueba el alcance de pérdida sobre bonos antes de cerrar un periodo con esa sanción. Anula este periodo sin pagos y vuelve a cerrarlo con la regla aprobada.']);
                }
                $liters = '0.000';
                $gross = '0.00000';
                $net = '0.00000';
                $details = [];
                foreach ($sources['entregas'][$producer->productor_id] ?? [] as $row) {
                    $d = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
                    $day = Carbon::parse($d['recolectada_at'])->setTimezone(config('app.timezone'))->toDateString();
                    $volume = bcadd((string) $d['litros'], '0', 3);
                    $daily[$day] = bcadd($daily[$day], $volume, 3);
                    $liters = bcadd($liters, $volume, 3);
                    $applied = [];
                    foreach ($sanctions as $s) {
                        if ($s['tarifa_penalizada'] === null) {
                            continue;
                        } $source = $s['fuente_snapshot'];
                        $sample = Carbon::parse($source['muestra_at'])->setTimezone(config('app.timezone'));
                        $scope = $s['regla_aplicada']['valores']['alcance_tarifa'];
                        $matches = match ($scope) {
                            'entrega' => (int) ($source['entrega_id'] ?? 0) === (int) $d['id'],'dia' => $sample->toDateString() === $day,'semana' => $sample->copy()->startOfWeek(Carbon::THURSDAY)->toDateString() === $p->desde->toDateString(),default => false
                        };
                        if ($matches) {
                            if (bccomp($s['tarifa_penalizada'], $price, 2) > 0) {
                                throw ValidationException::withMessages(['tarifa' => 'Una tarifa penalizada supera el precio estándar. Revisa las reglas aprobadas.']);
                            } $applied[$s['uuid']] = $s['tarifa_penalizada'];
                        }
                    }
                    $distinct = array_unique(array_values($applied));
                    if (count($distinct) > 1 && $rule['conflicto_tarifas'] === 'bloquear') {
                        throw ValidationException::withMessages(['tarifa' => 'Hay tarifas penalizadas distintas sobre los mismos litros. Requiere una regla aprobada de resolución.']);
                    } $effective = $price;
                    foreach ($distinct as $candidate) {
                        if (bccomp($candidate, $effective, 2) < 0) {
                            $effective = $candidate;
                        }
                    }
                    $gross = bcadd($gross, bcmul($volume, $price, 5), 5);
                    $net = bcadd($net, bcmul($volume, $effective, 5), 5);
                    $details[] = ['entrega' => $d['uuid_cliente'], 'fecha' => $day, 'litros' => $volume, 'precio_base' => $price, 'precio_aplicado' => $effective, 'sanciones' => array_keys($applied)];
                }
                $gross = $this->money($gross);
                $net = $loss ? '0.00' : $this->money($net);
                $penalties = bcsub($gross, $net, 2);
                $cheese = '0.00';
                $sales = [];
                foreach ($sources['ventas'][$producer->productor_id] ?? [] as $row) {
                    $sale = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
                    $cheese = bcadd($cheese, $sale['total'], 2);
                    $sales[] = ['uuid' => $sale['uuid'], 'total' => $sale['total'], 'detalles' => $sale['detalles']];
                } $total = bcsub($net, $cheese, 2);
                foreach ([$gross, $penalties, $cheese, $total] as $amount) {
                    if (bccomp($amount, '999999999999.99', 2) > 0 || bccomp($amount, '-999999999999.99', 2) < 0) {
                        throw ValidationException::withMessages(['importe' => 'El cálculo supera la capacidad monetaria admitida.']);
                    }
                }
                Liquidacion::create(['uuid' => (string) Str::uuid(), 'periodo_id' => $p->id, 'productor_id' => $producer->productor_id, 'productor_snapshot' => json_decode($producer->snapshot, true, 512, JSON_THROW_ON_ERROR), 'litros_diarios' => $daily, 'litros_total' => $liters, 'precio_litro' => $price, 'importe_bruto' => $gross, 'penalizaciones' => $penalties, 'descuentos_queso' => $cheese, 'total_base' => $total, 'regla_aplicada' => $p->regla_aplicada, 'detalle_calculo' => ['entregas' => $details, 'sanciones' => $sanctions->values()->all(), 'ventas' => $sales], 'perdida_liquidacion' => $loss, 'estado' => 'calculada']);
            }
            $p->update(['estado' => 'calculado']);
            $this->audit->record('liquidaciones', $p->uuid, 'calculo', $actor, $before, $p->toArray(), $reason);

            return $p;
        });
    }

    private function money(string $value): string
    {
        return bcadd($value, '0.005', 2);
    }
}
