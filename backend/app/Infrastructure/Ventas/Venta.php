<?php

namespace App\Infrastructure\Ventas;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venta extends Model
{
    protected $table = 'ventas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:2', 'descuento' => 'decimal:2', 'total' => 'decimal:2', 'cliente_snapshot' => 'array', 'tarifa_aplicada' => 'array', 'descontar_liquidacion' => 'boolean', 'vendida_at' => 'datetime', 'pagada_at' => 'datetime'];
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleVenta::class, 'venta_id');
    }
}
