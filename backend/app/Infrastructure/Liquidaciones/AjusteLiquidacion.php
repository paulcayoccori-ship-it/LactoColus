<?php

namespace App\Infrastructure\Liquidaciones;

use Illuminate\Database\Eloquent\Model;

class AjusteLiquidacion extends Model
{
    protected $table = 'ajustes_liquidacion';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['importe' => 'decimal:2', 'decidido_at' => 'datetime'];
    }
}
