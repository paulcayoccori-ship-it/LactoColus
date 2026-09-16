<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProduccionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administrar-produccion') ?? false;
    }

    public function rules(): array
    {
        return [];
    }

    public function payload(): array
    {
        return $this->only(['uuid', 'codigo', 'producido_at', 'tipo', 'observaciones', 'recepciones', 'moldes', 'delta_moldes', 'motivo', 'minimo', 'maximo']);
    }
}
