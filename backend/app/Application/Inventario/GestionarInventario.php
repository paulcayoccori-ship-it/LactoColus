<?php

namespace App\Application\Inventario;

use App\Application\Operacion\AuditarOperacion;
use App\Domain\Usuarios\UsuarioRepository;
use App\Infrastructure\Inventario\ExistenciaQueso;
use App\Infrastructure\Inventario\MovimientoInventario;
use App\Infrastructure\Produccion\LoteProduccion;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class GestionarInventario
{
    public function __construct(private UsuarioRepository $users, private AuditarOperacion $audit) {}

    public function move(int $actor, string $key, string $type, string $class, int $delta, string $reason, ?int $lot = null, ?int $sale = null): MovimientoInventario
    {
        Gate::forUser(User::findOrFail($actor))->authorize('administrar-ventas');

        return $this->users->underAdminLock(function () use ($actor, $key, $type, $class, $delta, $reason, $lot, $sale): MovimientoInventario {
            Gate::forUser(User::findOrFail($actor))->authorize('administrar-ventas');
            if ($existing = MovimientoInventario::where('clave', $key)->first()) {
                return $existing;
            }
            $stock = ExistenciaQueso::whereKey($type)->lockForUpdate()->firstOrFail();
            $before = $stock->moldes;
            $after = $before + $delta;
            if ($after < 0) {
                throw ValidationException::withMessages(['stock' => 'Stock insuficiente de '.str_replace('_', ' ', $type).'. Disponible: '.$before.' moldes.']);
            }
            $stock->update(['moldes' => $after]);

            return MovimientoInventario::create(['clave' => $key, 'tipo' => $type, 'clase' => $class, 'delta' => $delta, 'anterior' => $before, 'nuevo' => $after, 'usuario_id' => $actor, 'lote_id' => $lot, 'venta_id' => $sale, 'motivo' => $reason]);
        });
    }

    public function production(int $actor, LoteProduccion $lot): void
    {
        $this->move($actor, 'lote:'.$lot->uuid.':produccion', $lot->tipo, 'produccion', $lot->moldes, 'Finalización de lote', $lot->id);
    }

    public function productionAdjustment(int $actor, LoteProduccion $lot, string $uuid, int $delta, string $reason): void
    {
        $this->move($actor, 'lote:'.$lot->uuid.':ajuste:'.$uuid, $lot->tipo, 'ajuste', $delta, $reason, $lot->id);
    }

    public function annulProduction(int $actor, LoteProduccion $lot, string $reason): void
    {
        if ($lot->estado === 'finalizado') {
            $effective = $lot->moldes + (int) $lot->ajustes()->sum('delta_moldes');
            $this->move($actor, 'lote:'.$lot->uuid.':anulacion', $lot->tipo, 'anulacion', -$effective, $reason, $lot->id);
        }
    }

    public function adjust(int $actor, array $input): MovimientoInventario
    {
        Gate::forUser(User::findOrFail($actor))->authorize('administrar-ventas');
        $data = Validator::make($input, ['uuid' => ['required', 'uuid'], 'tipo' => ['required', 'in:paria_fresco,paria_pasteurizado'], 'delta' => ['required', 'integer', 'not_in:0', 'between:-10000000,10000000'], 'motivo' => ['required', 'string', 'max:2000']], ['required' => 'El campo :attribute es obligatorio.', 'uuid' => 'UUID inválido.', 'in' => 'Tipo de queso inválido.', 'integer' => 'La variación debe ser entera.', 'not_in' => 'La variación no puede ser cero.', 'between' => 'Cantidad fuera de capacidad.', 'max' => 'Motivo demasiado largo.'])->validate();
        $data['motivo'] = $this->audit->reason($data['motivo']);

        return $this->users->underAdminLock(function () use ($actor, $data): MovimientoInventario {
            $key = 'manual:'.$data['uuid'];
            $exists = MovimientoInventario::where('clave', $key)->exists();
            $move = $this->move($actor, $key, $data['tipo'], 'ajuste', (int) $data['delta'], $data['motivo']);
            if (! $exists) {
                $this->audit->record('inventario', $key, 'ajuste', $actor, ['stock' => $move->anterior], ['stock' => $move->nuevo], $data['motivo']);
            }

            return $move;
        });
    }
}
