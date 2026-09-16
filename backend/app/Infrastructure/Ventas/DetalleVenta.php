<?php

namespace App\Infrastructure\Ventas;

use Illuminate\Database\Eloquent\Model;

class DetalleVenta extends Model
{
    protected $table = 'detalles_venta';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['precio' => 'decimal:2', 'subtotal' => 'decimal:2'];
    }
}
