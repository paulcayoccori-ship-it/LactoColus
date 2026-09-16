<?php

namespace App\Infrastructure\Liquidaciones;

use Database\Factories\Infrastructure\Liquidaciones\PeriodoLiquidacionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(PeriodoLiquidacionFactory::class)]
class PeriodoLiquidacion extends Model
{
    use HasFactory;

    protected $table = 'periodos_liquidacion';

    protected $guarded = ['id', 'inicio_vigente'];

    protected function casts(): array
    {
        return ['desde' => 'date', 'hasta' => 'date', 'pago_previsto' => 'date', 'regla_aplicada' => 'array', 'cerrado_at' => 'datetime', 'aprobado_at' => 'datetime'];
    }

    public function liquidaciones(): HasMany
    {
        return $this->hasMany(Liquidacion::class, 'periodo_id');
    }
}
