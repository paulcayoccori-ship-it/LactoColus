<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AcopioJornadaRequest extends FormRequest
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
        return ['ruta_id' => ['required', 'integer', 'exists:rutas_acopio,id'], 'fecha_operativa' => ['required', 'date'], 'turno' => ['required', Rule::in(['primera_vuelta', 'segunda_vuelta'])], 'observaciones' => ['nullable', 'string', 'max:5000']];
    }

    public function messages(): array
    {
        return ['required' => 'El campo :attribute es obligatorio.', 'integer' => 'El identificador no es válido.', 'exists' => 'La ruta no existe.', 'date' => 'La fecha operativa no es válida.', 'in' => 'El turno no es válido.', 'max' => 'El campo :attribute supera la longitud permitida.'];
    }

    public function attributes(): array
    {
        return ['ruta_id' => 'ruta', 'fecha_operativa' => 'fecha operativa', 'turno' => 'turno', 'observaciones' => 'observaciones'];
    }
}
