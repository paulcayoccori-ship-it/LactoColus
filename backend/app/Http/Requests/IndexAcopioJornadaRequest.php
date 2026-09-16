<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAcopioJornadaRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['estado' => ['sometimes', Rule::in(['abierta', 'cerrada'])], 'ruta_id' => ['sometimes', 'integer', 'exists:rutas_acopio,id'], 'desde' => ['sometimes', 'date'], 'hasta' => ['sometimes', 'date', 'after_or_equal:desde'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return ['in' => 'El campo :attribute no es válido.', 'integer' => 'El campo :attribute debe ser un entero.', 'exists' => 'La ruta no existe.', 'date' => 'El campo :attribute no es una fecha válida.', 'after_or_equal' => 'El campo :attribute debe ser posterior o igual a la fecha desde.', 'min' => 'El campo :attribute debe ser al menos :min.', 'max' => 'El campo :attribute no debe superar :max.'];
    }

    public function attributes(): array
    {
        return ['estado' => 'estado', 'ruta_id' => 'ruta', 'desde' => 'fecha desde', 'hasta' => 'fecha hasta', 'per_page' => 'por página'];
    }
}
