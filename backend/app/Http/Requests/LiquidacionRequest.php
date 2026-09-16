<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LiquidacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('operar-liquidaciones') ?? false;
    }

    public function rules(): array
    {
        return [];
    }

    public function payload(): array
    {
        return $this->only(['uuid', 'desde', 'motivo', 'precio_litro', 'conflicto_tarifas', 'perdida_incluye_bonos', 'tipo', 'importe', 'origen_uuid', 'decision', 'uuid_externo', 'metodo', 'pagado_at']);
    }
}
