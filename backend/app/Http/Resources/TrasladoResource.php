<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrasladoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['uuid' => $this->uuid, 'productor_id' => $this->productor_id, 'ruta_actual_id' => $this->ruta_actual_id, 'ruta_solicitada_id' => $this->ruta_solicitada_id, 'solicitada_at' => $this->solicitada_at->toIso8601String(), 'fecha_efectiva' => $this->fecha_efectiva->toDateString(), 'motivo' => $this->motivo, 'estado' => $this->estado, 'comentario' => $this->comentario, 'decidida_at' => $this->decidida_at?->toIso8601String(), 'aplicada_at' => $this->aplicada_at?->toIso8601String(), 'regla_aplicada' => $this->regla_aplicada, 'advertencia' => $this->ultimo_error];
    }
}
