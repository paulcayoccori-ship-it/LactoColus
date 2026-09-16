<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCalidadJornadaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('operar-calidad') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fecha' => ['sometimes', 'date'],
            'estado' => ['sometimes', Rule::in(['abierta', 'cerrada'])],
            'recolector_id' => ['sometimes', 'integer', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return ['in' => 'El campo :attribute no es válido.', 'integer' => 'El campo :attribute debe ser un entero.', 'exists' => 'El recolector no existe.', 'date' => 'El campo :attribute no es una fecha válida.'];
    }

    public function attributes(): array
    {
        return ['fecha' => 'fecha', 'estado' => 'estado', 'recolector_id' => 'recolector'];
    }
}
