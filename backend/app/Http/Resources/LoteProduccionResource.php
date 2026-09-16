<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoteProduccionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $molds = $this->moldes + (int) ($this->ajustes_sum_delta_moldes ?? 0);

        return $this->resource->only(['uuid', 'codigo', 'tipo', 'litros_cuba', 'moldes', 'rendimiento', 'responsable_id', 'estado', 'observaciones', 'regla_aplicada']) + ['producido_at' => $this->producido_at->toIso8601String(), 'moldes_efectivos' => $molds, 'rendimiento_efectivo' => $this->estado === 'finalizado' ? bcdiv(bcmul((string) $molds, '100', 6), $this->litros_cuba, 6) : null, 'recepciones' => $this->whenLoaded('usos'), 'ajustes' => $this->whenLoaded('ajustes'), 'alerta' => $this->whenLoaded('alerta')];
    }
}
