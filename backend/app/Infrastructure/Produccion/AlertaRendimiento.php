<?php

namespace App\Infrastructure\Produccion;

use Illuminate\Database\Eloquent\Model;

class AlertaRendimiento extends Model
{
    protected $table = 'alertas_rendimiento';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rendimiento' => 'decimal:6', 'regla_aplicada' => 'array'];
    }
}
