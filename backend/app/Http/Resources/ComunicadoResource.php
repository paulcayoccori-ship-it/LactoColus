<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ComunicadoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['uuid' => $this->uuid, 'titulo' => $this->titulo, 'contenido' => $this->contenido, 'tipo' => $this->tipo, 'publicar_at' => $this->publicar_at->toIso8601String(), 'vence_at' => $this->vence_at?->toIso8601String(), 'estado' => $this->visibleState(), 'leida_at' => $this->leida_at];
    }
}
