<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcopioSyncRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->active === true && $this->user()->hasRole('recolector', 'web');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['entregas' => ['required', 'array', 'min:1', 'max:500']];
    }

    public function messages(): array
    {
        return ['required' => 'El campo :attribute es obligatorio.', 'array' => 'Las entregas deben ser una lista.', 'min' => 'Incluye al menos una entrega.', 'max' => 'El lote no puede superar 500 entregas.', 'uuid' => 'El UUID no es válido.', 'integer' => 'El identificador no es válido.', 'numeric' => 'Los litros deben ser numéricos.', 'gt' => 'Los litros deben ser mayores que cero.', 'date' => 'La fecha de recolección no es válida.'];
    }
}
