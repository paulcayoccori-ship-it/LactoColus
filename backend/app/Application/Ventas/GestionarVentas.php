<?php

namespace App\Application\Ventas;

use App\Application\Inventario\GestionarInventario;
use App\Application\Liquidaciones\ProtegerFuentesLiquidacion;
use App\Application\Operacion\AuditarOperacion;
use App\Application\Operacion\ReglasOperativas;
use App\Domain\Usuarios\UsuarioRepository;
use App\Infrastructure\Operacion\ReglaOperativa;
use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Ventas\Cliente;
use App\Infrastructure\Ventas\DetalleVenta;
use App\Infrastructure\Ventas\Venta;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class GestionarVentas
{
    public function __construct(private UsuarioRepository $users, private AuditarOperacion $audit, private ReglasOperativas $rules, private GestionarInventario $inventory, private ProtegerFuentesLiquidacion $sources) {}

    public function authorize(int $actor): void
    {
        Gate::forUser(User::findOrFail($actor))->authorize('administrar-ventas');
    }

    public function customer(int $actor, array $input, ?int $id = null): Cliente
    {
        $this->authorize($actor);
        if (($input['productor_id'] ?? null) === '') {
            $input['productor_id'] = null;
        }
        $data = Validator::make($input, ['nombre' => ['required', 'string', 'max:200'], 'categoria' => ['required', 'in:mayorista,proveedor,publico_general'], 'productor_id' => ['nullable', 'integer'], 'activo' => ['required', 'boolean']], ['required' => 'El campo :attribute es obligatorio.', 'max' => 'El nombre es demasiado largo.', 'in' => 'Categoría no válida.', 'integer' => 'Productor no válido.', 'boolean' => 'Estado no válido.'])->validate();
        $reason = $id ? $this->audit->reason((string) ($input['motivo'] ?? '')) : 'Registro de cliente';

        return $this->users->underAdminLock(function () use ($actor, $data, $id, $reason): Cliente {
            $this->authorize($actor);
            if ($data['categoria'] === 'proveedor') {
                $producer = Productor::whereKey($data['productor_id'] ?? null)->lockForUpdate()->first();
                if (! $producer || ! $producer->estado) {
                    throw ValidationException::withMessages(['productor_id' => 'Un cliente proveedor debe vincularse a un productor activo.']);
                }
            } elseif (! empty($data['productor_id'])) {
                throw ValidationException::withMessages(['productor_id' => 'Solo la categoría proveedor admite un productor.']);
            }
            $client = $id ? Cliente::whereKey($id)->lockForUpdate()->firstOrFail() : new Cliente(['uuid' => (string) Str::uuid()]);
            $before = $client->toArray();
            try {
                $client->fill($data)->save();
            } catch (UniqueConstraintViolationException $e) {
                throw ValidationException::withMessages(['productor_id' => 'El productor ya tiene un cliente registrado.']);
            }
            $this->audit->record('ventas', $client->uuid, $id ? 'cliente_correccion' : 'cliente_creacion', $actor, $before, $client->toArray(), $reason);

            return $client;
        });
    }

    public function create(int $actor, array $input): Venta
    {
        $this->authorize($actor);
        $uuid = Validator::make($input, ['uuid' => ['required', 'uuid']], ['required' => 'El UUID es obligatorio.', 'uuid' => 'El UUID no es válido.'])->validate()['uuid'];
        if ($existing = Venta::where('uuid', $uuid)->first()) {
            return $existing;
        }
        $data = Validator::make($input, ['uuid' => ['required', 'uuid'], 'cliente_id' => ['required', 'integer'], 'vendida_at' => ['required', 'date', 'before_or_equal:now'], 'descuento' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999.99'], 'descontar_liquidacion' => ['required', 'boolean'], 'detalles' => ['required', 'array', 'min:1', 'max:2'], 'detalles.*.tipo' => ['required', 'in:paria_fresco,paria_pasteurizado', 'distinct'], 'detalles.*.moldes' => ['required', 'integer', 'min:1', 'max:10000000'], 'observaciones' => ['nullable', 'string', 'max:5000']], ['required' => 'El campo :attribute es obligatorio.', 'integer' => 'La cantidad debe ser entera.', 'numeric' => 'El importe debe ser numérico.', 'decimal' => 'Usa como máximo dos decimales monetarios.', 'min' => 'Cantidad o importe fuera del mínimo.', 'max' => 'Cantidad o importe fuera de capacidad.', 'in' => 'Tipo de queso inválido.', 'distinct' => 'No repitas el tipo de queso.', 'date' => 'Fecha no válida.', 'before_or_equal' => 'La fecha de venta no puede estar en el futuro.', 'array' => 'Envía una lista de detalles.', 'boolean' => 'Indica si se descontará de la liquidación.'])->validate();

        return $this->users->underAdminLock(function () use ($actor, $data): Venta {
            $this->authorize($actor);
            if ($existing = Venta::where('uuid', $data['uuid'])->first()) {
                return $existing;
            }
            $client = Cliente::whereKey($data['cliente_id'])->lockForUpdate()->first();
            if (! $client || ! $client->activo) {
                throw ValidationException::withMessages(['cliente_id' => 'Selecciona un cliente activo.']);
            }
            if ($data['descontar_liquidacion']) {
                $this->sources->date($data['vendida_at']);
            }
            if ($data['descontar_liquidacion'] && $client->categoria !== 'proveedor') {
                throw ValidationException::withMessages(['descontar_liquidacion' => 'Solo los proveedores pueden descontar compras de su liquidación.']);
            }
            $rule = $this->rules->current('ventas');
            if ($client->categoria === 'proveedor' && $rule['valores']['limite_proveedor'] === null) {
                throw ValidationException::withMessages(['tarifa' => 'Configura primero el límite de compra para proveedores.']);
            }
            $price = $rule['valores']['precios'][$client->categoria];
            $subtotal = '0.00';
            $details = [];
            foreach ($data['detalles'] as $detail) {
                $amount = bcmul($price, (string) $detail['moldes'], 2);
                $subtotal = bcadd($subtotal, $amount, 2);
                $details[] = ['tipo' => $detail['tipo'], 'moldes' => $detail['moldes'], 'precio' => $price, 'subtotal' => $amount];
            }
            if (bccomp($subtotal, '999999999999.99', 2) > 0) {
                throw ValidationException::withMessages(['detalles' => 'El subtotal supera la capacidad monetaria permitida.']);
            }
            if (bccomp((string) $data['descuento'], $subtotal, 2) > 0) {
                throw ValidationException::withMessages(['descuento' => 'El descuento no puede superar el subtotal.']);
            }
            $sale = Venta::create(['uuid' => $data['uuid'], 'cliente_id' => $client->id, 'productor_id' => $client->productor_id, 'cliente_snapshot' => $client->only(['uuid', 'nombre', 'categoria', 'productor_id']), 'vendida_at' => Carbon::parse($data['vendida_at'])->setTimezone(config('app.timezone')), 'subtotal' => $subtotal, 'descuento' => bcadd((string) $data['descuento'], '0', 2), 'total' => bcsub($subtotal, (string) $data['descuento'], 2), 'tarifa_aplicada' => $rule, 'descontar_liquidacion' => $data['descontar_liquidacion'], 'responsable_id' => $actor, 'observaciones' => $data['observaciones'] ?? null, 'estado' => 'borrador']);
            foreach ($details as $detail) {
                $sale->detalles()->create($detail);
            }
            $this->audit->record('ventas', $sale->uuid, 'creacion', $actor, [], $sale->load('detalles')->toArray(), 'Creación de venta');

            return $sale;
        });
    }

    public function confirm(int $actor, string $uuid): Venta
    {
        return $this->locked($actor, $uuid, function (Venta $sale) use ($actor): Venta {
            if (in_array($sale->estado, ['confirmada', 'pagada'], true)) {
                return $sale;
            }
            $this->state($sale, ['borrador']);
            if ($sale->descontar_liquidacion) {
                $this->sources->date($sale->vendida_at);
            }
            $before = $sale->toArray();
            if ($sale->productor_id) {
                $producer = Productor::whereKey($sale->productor_id)->lockForUpdate()->first();
                if (! $producer || ! $producer->estado) {
                    throw ValidationException::withMessages(['productor_id' => 'El productor debe estar activo para confirmar la compra.']);
                }
                $values = $sale->tarifa_aplicada['valores'];
                $quantity = (int) $sale->detalles->sum('moldes');
                if ($values['alcance_limite'] === 'semana_jueves_miercoles') {
                    $start = $sale->vendida_at->copy()->startOfWeek(Carbon::THURSDAY)->startOfDay();
                    $end = $start->copy()->addDays(7);
                    $used = DetalleVenta::whereIn('venta_id', Venta::where('productor_id', $sale->productor_id)->whereIn('estado', ['confirmada', 'pagada'])->where('vendida_at', '>=', $start)->where('vendida_at', '<', $end)->select('id'))->sum('moldes');
                    $quantity += (int) $used;
                }
                if ($quantity > (int) $values['limite_proveedor']) {
                    throw ValidationException::withMessages(['limite' => 'La compra supera el límite configurado para proveedores.']);
                }
            }
            foreach ($sale->detalles->sortBy('tipo') as $detail) {
                $this->inventory->move($actor, 'venta:'.$sale->uuid.':confirmacion:'.$detail->tipo, $detail->tipo, 'venta', -$detail->moldes, 'Confirmación de venta', null, $sale->id);
            }
            $sale->update(['estado' => 'confirmada']);
            $this->audit->record('ventas', $sale->uuid, 'confirmacion', $actor, $before, $sale->toArray(), 'Venta confirmada');

            return $sale;
        });
    }

    public function pay(int $actor, string $uuid, array $input): Venta
    {
        $this->authorize($actor);
        $data = Validator::make($input, ['metodo_pago' => ['required', 'string', 'max:80'], 'pagada_at' => ['required', 'date', 'before_or_equal:now']], ['required' => 'El campo :attribute es obligatorio.', 'date' => 'Fecha no válida.', 'max' => 'Método demasiado largo.', 'before_or_equal' => 'La fecha de pago no puede estar en el futuro.'])->validate();

        return $this->locked($actor, $uuid, function (Venta $sale) use ($actor, $data): Venta {
            $this->sources->sale($sale->id);
            if ($sale->estado === 'pagada') {
                return $sale;
            }
            $this->state($sale, ['confirmada']);
            $before = $sale->toArray();
            $sale->update(['estado' => 'pagada', 'metodo_pago' => $data['metodo_pago'], 'pagada_at' => Carbon::parse($data['pagada_at'])->setTimezone(config('app.timezone')), 'pagada_por' => $actor]);
            $this->audit->record('ventas', $sale->uuid, 'pago', $actor, $before, $sale->toArray(), 'Registro de pago');

            return $sale;
        });
    }

    public function annul(int $actor, string $uuid, string $reason): Venta
    {
        $this->authorize($actor);
        $reason = $this->audit->reason($reason);

        return $this->locked($actor, $uuid, function (Venta $sale) use ($actor, $reason): Venta {
            $this->sources->sale($sale->id);
            if ($sale->estado === 'anulada') {
                return $sale;
            }
            $before = $sale->toArray();
            if (in_array($sale->estado, ['confirmada', 'pagada'], true)) {
                foreach ($sale->detalles->sortBy('tipo') as $detail) {
                    $this->inventory->move($actor, 'venta:'.$sale->uuid.':anulacion:'.$detail->tipo, $detail->tipo, 'reversion', $detail->moldes, $reason, null, $sale->id);
                }
            }
            $sale->update(['estado' => 'anulada']);
            $this->audit->record('ventas', $sale->uuid, 'anulacion', $actor, $before, $sale->toArray(), $reason);

            return $sale;
        });
    }

    public function pricing(int $actor, array $input, string $reason): array
    {
        $this->authorize($actor);
        $reason = $this->audit->reason($reason);
        if (($input['limite_proveedor'] ?? null) === '') {
            $input['limite_proveedor'] = null;
        }
        $validation = ['precios' => ['required', 'array'], 'limite_proveedor' => ['nullable', 'integer', 'between:1,10000'], 'alcance_limite' => ['required', 'in:venta,semana_jueves_miercoles']];
        foreach (['mayorista', 'proveedor', 'publico_general'] as $category) {
            $validation['precios.'.$category] = ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999.99'];
        }
        $values = Validator::make($input, $validation, ['required' => 'Completa el campo :attribute.', 'numeric' => 'El precio debe ser numérico.', 'decimal' => 'Usa hasta dos decimales.', 'gt' => 'El precio debe ser positivo.', 'max' => 'Precio fuera de capacidad.', 'integer' => 'El límite debe ser entero.', 'between' => 'Límite fuera de capacidad.', 'in' => 'Selecciona un alcance válido.'])->validate();
        $values['limite_proveedor'] ??= null;
        foreach (['mayorista', 'proveedor', 'publico_general'] as $category) {
            $values['precios'][$category] = bcadd((string) $values['precios'][$category], '0', 2);
        }

        return $this->users->underAdminLock(function () use ($actor, $values, $reason): array {
            $this->authorize($actor);
            $before = $this->rules->current('ventas');
            ReglaOperativa::create(['clave' => 'ventas', 'version' => $before['version'] + 1, 'valores' => $values, 'autor_id' => $actor, 'motivo' => $reason]);
            $after = $this->rules->current('ventas');
            $this->audit->record('ventas', 'tarifas', 'configuracion', $actor, $before, $after, $reason);

            return $after;
        });
    }

    private function locked(int $actor, string $uuid, \Closure $callback): Venta
    {
        $this->authorize($actor);

        return $this->users->underAdminLock(function () use ($actor, $uuid, $callback): Venta {
            $this->authorize($actor);

            return $callback(Venta::with('detalles')->where('uuid', $uuid)->lockForUpdate()->firstOrFail());
        });
    }

    private function state(Venta $sale, array $states): void
    {
        if (! in_array($sale->estado, $states, true)) {
            throw ValidationException::withMessages(['estado' => 'La operación no está permitida para una venta '.$sale->estado.'.']);
        }
    }
}
