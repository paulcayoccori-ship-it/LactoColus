<?php

namespace App\Http\Resources;

use App\Application\Liquidaciones\GestionarLiquidaciones;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LiquidacionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['uuid' => $this->uuid, 'periodo' => ['uuid' => $this->periodo->uuid, 'desde' => $this->periodo->desde->toDateString(), 'hasta' => $this->periodo->hasta->toDateString(), 'pago_previsto' => $this->periodo->pago_previsto->toDateString()], 'productor' => $this->productor_snapshot, 'estado' => $this->estado, 'litros_diarios' => $this->litros_diarios, 'litros_total' => $this->litros_total, 'precio_litro' => $this->precio_litro, 'importe_bruto' => $this->importe_bruto, 'penalizaciones' => $this->penalizaciones, 'descuentos_queso' => $this->descuentos_queso, 'total_base' => $this->total_base, 'totales' => app(GestionarLiquidaciones::class)->totals($this->resource), 'regla_aplicada' => $this->regla_aplicada, 'detalle_calculo' => $this->detalle_calculo, 'ajustes' => $this->ajustes->map(fn ($a) => $a->only(['uuid', 'tipo', 'importe', 'estado', 'motivo', 'comentario', 'created_at', 'decidido_at'])), 'pago' => $this->pago?->only(['uuid_externo', 'importe', 'metodo', 'pagado_at'])];
    }
}
