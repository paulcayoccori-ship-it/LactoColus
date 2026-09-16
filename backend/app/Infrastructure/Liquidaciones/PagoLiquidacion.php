<?php

namespace App\Infrastructure\Liquidaciones;

use Illuminate\Database\Eloquent\Model;

class PagoLiquidacion extends Model
{
    protected $table = 'pagos_liquidacion';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['importe' => 'decimal:2', 'pagado_at' => 'datetime', 'snapshot' => 'array'];
    }
}
