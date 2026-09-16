<?php

namespace App\Application\Liquidaciones;

use App\Application\Operacion\AuditarOperacion;
use App\Application\Operacion\ReglasOperativas;
use App\Domain\Usuarios\UsuarioRepository;
use App\Infrastructure\Acopios\EntregaAcopio;
use App\Infrastructure\Acopios\JornadaAcopio;
use App\Infrastructure\Calidad\AnalisisCalidad;
use App\Infrastructure\Liquidaciones\PeriodoLiquidacion;
use App\Infrastructure\Operacion\ReglaOperativa;
use App\Infrastructure\Penalizaciones\SancionCalidad;
use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Ventas\Venta;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class GestionarPeriodos
{
    public function __construct(private UsuarioRepository $users, private AuditarOperacion $audit, private ReglasOperativas $rules) {}

    public function authorize(int $actor): void
    {
        Gate::forUser(User::findOrFail($actor))->authorize('administrar-liquidaciones');
    }

    public function create(int $actor, array $input): PeriodoLiquidacion
    {
        $this->authorize($actor);
        $data = Validator::make($input, ['uuid' => ['required', 'uuid'], 'desde' => ['required', 'date_format:Y-m-d'], 'motivo' => ['required', 'string', 'max:2000']], ['required' => 'Completa :attribute.', 'uuid' => 'UUID inválido.', 'date_format' => 'Usa fecha AAAA-MM-DD.', 'max' => 'Motivo demasiado largo.'])->validate();
        $reason = $this->audit->reason($data['motivo']);
        $start = Carbon::parse($data['desde']);
        if (! $start->isThursday()) {
            throw ValidationException::withMessages(['desde' => 'El periodo debe comenzar un jueves.']);
        } $end = $start->copy()->addDays(6);

        return $this->users->underAdminLock(function () use ($actor, $data, $start, $end, $reason): PeriodoLiquidacion {
            $this->authorize($actor);
            if ($existing = PeriodoLiquidacion::where('uuid', $data['uuid'])->first()) {
                return $existing;
            } if (PeriodoLiquidacion::where('estado', '!=', 'anulado')->whereDate('desde', '<=', $end)->whereDate('hasta', '>=', $start)->exists()) {
                throw ValidationException::withMessages(['desde' => 'El periodo se superpone con otro vigente.']);
            } $r = PeriodoLiquidacion::create(['uuid' => $data['uuid'], 'desde' => $start, 'hasta' => $end, 'pago_previsto' => $end->copy()->addDays(2), 'estado' => 'abierto', 'creado_por' => $actor, 'motivo' => $reason]);
            $this->audit->record('liquidaciones', $r->uuid, 'periodo_creacion', $actor, [], $r->toArray(), $reason);

            return $r;
        });
    }

    public function close(int $actor, string $uuid, string $reason): PeriodoLiquidacion
    {
        $this->authorize($actor);
        $reason = $this->audit->reason($reason);

        return $this->users->underAdminLock(function () use ($actor, $uuid, $reason): PeriodoLiquidacion {
            $this->authorize($actor);
            $period = PeriodoLiquidacion::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if (in_array($period->estado, ['cerrado', 'calculado', 'aprobado', 'pagado'], true)) {
                return $period;
            } if ($period->estado !== 'abierto' || $period->hasta->gte(today())) {
                throw ValidationException::withMessages(['periodo' => 'Solo se cierra un periodo abierto después de terminar el miércoles.']);
            } $start = $period->desde->toDateString();
            $end = $period->hasta->toDateString();
            $open = JornadaAcopio::where('estado', 'abierta')->where(function ($q) use ($start, $end): void {
                $q->whereBetween('fecha_operativa', [$start, $end])->orWhereHas('entregas', fn ($q) => $q->whereDate('recolectada_at', '>=', $start)->whereDate('recolectada_at', '<=', $end));
            })->exists();
            if ($open) {
                throw ValidationException::withMessages(['jornadas' => 'Cierra o anula las jornadas abiertas del periodo antes de liquidar.']);
            }
            $deliveries = EntregaAcopio::whereHas('jornada', fn ($q) => $q->where('estado', 'cerrada'))->whereDate('recolectada_at', '>=', $start)->whereDate('recolectada_at', '<=', $end)->orderBy('id')->lockForUpdate()->get();
            $sales = Venta::with('detalles')->where('estado', 'confirmada')->where('descontar_liquidacion', true)->whereDate('vendida_at', '>=', $start)->whereDate('vendida_at', '<=', $end)->orderBy('id')->lockForUpdate()->get();
            $ids = $deliveries->pluck('productor_id')->merge($sales->pluck('productor_id'))->unique()->values()->all();
            $deliveryIds = $deliveries->pluck('id')->all();
            $sanctions = SancionCalidad::whereIn('productor_id', $ids)->orderBy('id')->lockForUpdate()->get();
            $relevant = $sanctions->filter(function ($s) use ($start, $end, $deliveryIds): bool {
                $date = Carbon::parse($s->fuente_snapshot['muestra_at'])->setTimezone(config('app.timezone'))->toDateString();

                return ($date >= $start && $date <= $end) || in_array($s->fuente_snapshot['entrega_id'] ?? null, $deliveryIds, true);
            });
            if ($sanctions->contains('requiere_revision', true) || $relevant->contains(fn ($s) => in_array($s->estado, ['pendiente', 'pendiente_configuracion'], true))) {
                throw ValidationException::withMessages(['sanciones' => 'Hay sanciones sin decidir o con revisión pendiente para los productores del periodo.']);
            }
            $unevaluated = AnalisisCalidad::whereIn('productor_id', $ids)->where('estado', '!=', 'anulado')->where('agua_anadida', '>', 0)->whereDate('muestra_at', '>=', $start)->whereDate('muestra_at', '<=', $end)->whereNotIn('id', $sanctions->pluck('analisis_id'))->exists();
            if ($unevaluated) {
                throw ValidationException::withMessages(['sanciones' => 'Evalúa los análisis con agua añadida antes de cerrar el periodo.']);
            }
            $rule = $this->rules->current('liquidaciones');
            $before = $period->toArray();
            foreach ($deliveries as $d) {
                DB::table('periodo_entregas')->insert(['periodo_id' => $period->id, 'fuente_id' => $d->id, 'productor_id' => $d->productor_id, 'snapshot' => json_encode($d->only(['id', 'uuid_cliente', 'productor_id', 'ruta_id', 'jornada_id', 'recolector_id', 'litros', 'recolectada_at']), JSON_THROW_ON_ERROR)]);
            }
            foreach ($relevant->where('estado', 'aprobada') as $s) {
                DB::table('periodo_sanciones')->insert(['periodo_id' => $period->id, 'fuente_id' => $s->id, 'productor_id' => $s->productor_id, 'snapshot' => json_encode($s->toArray(), JSON_THROW_ON_ERROR)]);
            }
            foreach ($sales as $sale) {
                if (DB::table('periodo_ventas')->where('venta_id', $sale->id)->where('vigente', true)->exists()) {
                    throw ValidationException::withMessages(['ventas' => 'Una compra ya pertenece a otra liquidación vigente.']);
                } DB::table('periodo_ventas')->insert(['periodo_id' => $period->id, 'venta_id' => $sale->id, 'productor_id' => $sale->productor_id, 'snapshot' => json_encode($sale->toArray(), JSON_THROW_ON_ERROR), 'vigente' => true]);
            }
            foreach (Productor::withTrashed()->whereIn('id', $ids)->get() as $p) {
                DB::table('periodo_productores')->insert(['periodo_id' => $period->id, 'productor_id' => $p->id, 'snapshot' => json_encode($p->only(['codigo', 'nombres', 'apellidos']), JSON_THROW_ON_ERROR)]);
            }
            $period->update(['estado' => 'cerrado', 'cerrado_at' => now(), 'cerrado_por' => $actor, 'regla_aplicada' => $rule]);
            $this->audit->record('liquidaciones', $uuid, 'periodo_cierre', $actor, $before, $period->toArray() + ['entregas' => $deliveries->count(), 'ventas' => $sales->count(), 'productores' => count($ids)], $reason);

            return $period;
        });
    }

    public function configure(int $actor, array $input): array
    {
        $this->authorize($actor);
        $data = Validator::make($input, ['precio_litro' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999.99'], 'conflicto_tarifas' => ['required', 'in:bloquear,menor_tarifa'], 'perdida_incluye_bonos' => ['present', 'nullable', 'boolean'], 'motivo' => ['required', 'string', 'max:2000']], ['required' => 'Completa :attribute.', 'present' => 'Define si la pérdida incluye bonos o deja pendiente su aprobación.', 'numeric' => 'Precio numérico requerido.', 'decimal' => 'Usa hasta dos decimales.', 'gt' => 'Precio positivo requerido.', 'max' => 'Valor fuera de capacidad.', 'in' => 'Regla inválida.', 'boolean' => 'Selecciona sí o no.'])->validate();
        $reason = $this->audit->reason($data['motivo']);
        unset($data['motivo']);
        $data['precio_litro'] = bcadd((string) $data['precio_litro'], '0', 2);

        return $this->users->underAdminLock(function () use ($actor, $data, $reason): array {
            $this->authorize($actor);
            $before = $this->rules->current('liquidaciones');
            ReglaOperativa::create(['clave' => 'liquidaciones', 'version' => $before['version'] + 1, 'valores' => $data, 'autor_id' => $actor, 'motivo' => $reason]);
            $after = $this->rules->current('liquidaciones');
            $this->audit->record('liquidaciones', 'reglas', 'configuracion', $actor, $before, $after, $reason);

            return $after;
        });
    }

    public function annul(int $actor, string $uuid, string $reason): PeriodoLiquidacion
    {
        $this->authorize($actor);
        $reason = $this->audit->reason($reason);

        return $this->users->underAdminLock(function () use ($actor, $uuid, $reason): PeriodoLiquidacion {
            $this->authorize($actor);
            $p = PeriodoLiquidacion::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($p->estado === 'anulado') {
                return $p;
            } if ($p->liquidaciones()->whereHas('pago')->exists()) {
                throw ValidationException::withMessages(['pagos' => 'El periodo tiene pagos registrados. Corrige mediante ajustes en un periodo posterior.']);
            } $before = $p->toArray();
            $p->liquidaciones()->update(['estado' => 'anulada']);
            DB::table('periodo_ventas')->where('periodo_id', $p->id)->update(['vigente' => false]);
            $p->update(['estado' => 'anulado']);
            $this->audit->record('liquidaciones', $uuid, 'periodo_anulacion', $actor, $before, $p->toArray(), $reason);

            return $p;
        });
    }
}
