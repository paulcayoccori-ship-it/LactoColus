<?php

namespace App\Application\Ventas;

use App\Infrastructure\Inventario\ExistenciaQueso;
use App\Infrastructure\Inventario\MovimientoInventario;
use App\Infrastructure\Operacion\AuditoriaOperativa;
use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Ventas\Cliente;
use App\Infrastructure\Ventas\Venta;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

final class ConsultarVentas
{
    public function authorize(int $actor): void
    {
        Gate::forUser(User::findOrFail($actor))->authorize('administrar-ventas');
    }

    public function listing(int $actor, array $filters = []): LengthAwarePaginator
    {
        $this->authorize($actor);

        return Venta::with('detalles')->when($filters['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))->when($filters['cliente_id'] ?? null, fn ($q, $v) => $q->where('cliente_id', $v))->latest('vendida_at')->latest('id')->paginate(15);
    }

    public function find(int $actor, string $uuid): Venta
    {
        $this->authorize($actor);

        return Venta::with('detalles')->where('uuid', $uuid)->firstOrFail();
    }

    public function options(int $actor): array
    {
        $this->authorize($actor);

        return ['clientes' => Cliente::orderBy('nombre')->get()->toArray(), 'productores' => Productor::where('estado', true)->orderBy('codigo')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->codigo.' — '.$p->nombres.' '.$p->apellidos])->all(), 'stock' => ExistenciaQueso::orderBy('tipo')->get()->toArray()];
    }

    public function movements(int $actor): LengthAwarePaginator
    {
        $this->authorize($actor);

        return MovimientoInventario::latest('id')->paginate(20, ['*'], 'movimientos');
    }

    public function customer(int $actor, int $id): array
    {
        $this->authorize($actor);

        return Cliente::findOrFail($id)->only(['nombre', 'categoria', 'productor_id', 'activo']);
    }

    public function audit(int $actor, string $uuid): array
    {
        $this->authorize($actor);

        return AuditoriaOperativa::with('usuario:id,name')->where('modulo', 'ventas')->where('entidad', $uuid)->latest('id')->get()->toArray();
    }
}
