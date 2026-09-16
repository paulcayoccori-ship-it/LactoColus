<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VentaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['uuid' => $this->uuid, 'cliente' => $this->cliente_snapshot, 'productor_id' => $this->productor_id, 'vendida_at' => $this->vendida_at->toIso8601String(), 'estado' => $this->estado, 'subtotal' => $this->subtotal, 'descuento' => $this->descuento, 'total' => $this->total, 'moneda' => 'PEN', 'descontar_liquidacion' => $this->descontar_liquidacion, 'tarifa_aplicada' => $this->tarifa_aplicada, 'detalles' => $this->whenLoaded('detalles', fn () => $this->detalles->map(fn ($d) => $d->only(['tipo', 'moldes', 'precio', 'subtotal']))), 'metodo_pago' => $this->metodo_pago, 'pagada_at' => $this->pagada_at?->toIso8601String(), 'observaciones' => $this->observaciones];
    }
}
