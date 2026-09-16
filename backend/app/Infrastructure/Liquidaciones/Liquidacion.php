<?php

namespace App\Infrastructure\Liquidaciones;

use Database\Factories\Infrastructure\Liquidaciones\LiquidacionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[UseFactory(LiquidacionFactory::class)]
class Liquidacion extends Model
{
    use HasFactory;

    protected $table = 'liquidaciones';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['productor_snapshot' => 'array', 'litros_diarios' => 'array', 'litros_total' => 'decimal:3', 'precio_litro' => 'decimal:2', 'importe_bruto' => 'decimal:2', 'penalizaciones' => 'decimal:2', 'descuentos_queso' => 'decimal:2', 'total_base' => 'decimal:2', 'regla_aplicada' => 'array', 'detalle_calculo' => 'array', 'perdida_liquidacion' => 'boolean'];
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(PeriodoLiquidacion::class, 'periodo_id');
    }

    public function ajustes(): HasMany
    {
        return $this->hasMany(AjusteLiquidacion::class, 'liquidacion_id');
    }

    public function pago(): HasOne
    {
        return $this->hasOne(PagoLiquidacion::class, 'liquidacion_id');
    }
}
