<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SincronizarCalidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('operar-calidad') ?? false;
    }

    public function rules(): array
    {
        return ['analisis' => ['required', 'array', 'min:1', 'max:100']];
    }

    public function messages(): array
    {
        return ['analisis.required' => 'Envía los análisis a sincronizar.', 'analisis.array' => 'El lote debe ser una lista.', 'analisis.min' => 'Envía al menos un análisis.', 'analisis.max' => 'El lote admite hasta 100 análisis.'];
    }
}
