<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_intersect_key($this->resource, array_flip(['id', 'codigo', 'dni', 'nombres', 'apellidos', 'celular', 'email', 'direccion', 'comunidad', 'estado', 'created_at', 'updated_at']));
    }
}
