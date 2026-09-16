<?php

namespace App\Http\Resources;

use App\Domain\Calidad\ParametrosCalidad;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnalisisCalidadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->resource->only(['uuid_publico', 'uuid_externo', 'productor_id', 'jornada_id', 'entrega_id', 'ruta_id', 'responsable_id', 'equipo', 'fuente', 'estado', 'perfil_id', 'limites_aplicados', 'advertencias', 'observaciones']);
        foreach (ParametrosCalidad::CAMPOS as $key => $field) {
            $data[$key] = $this->resource->{$key};
        }

        return $data + ['muestra_at' => $this->muestra_at->toIso8601String(), 'sincronizada_at' => $this->sincronizada_at->toIso8601String()];
    }
}
