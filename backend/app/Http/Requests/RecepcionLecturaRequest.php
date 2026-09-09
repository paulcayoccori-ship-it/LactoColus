<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecepcionLecturaRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->active === true && ($this->user()->isActiveAdministrator() || $this->user()->hasRole('recolector', 'web'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['jornada_uuid' => ['required', 'uuid'], 'uuid_lectura_externa' => ['required', 'uuid'], 'litros_planta' => ['required', 'numeric', 'gt:0', 'max:999999999.999'], 'recibida_at' => ['required', 'date'], 'fuente_medicion' => ['required', Rule::in(['manual', 'sensor'])], 'observaciones' => ['nullable', 'string', 'max:5000']];
    }

    public function messages(): array
    {
        return ['required' => 'El campo :attribute es obligatorio.', 'uuid' => 'El identificador no es válido.', 'numeric' => 'Los litros deben ser numéricos.', 'gt' => 'Los litros deben ser mayores que cero.', 'max' => 'El campo :attribute supera el máximo permitido.', 'date' => 'La fecha de recepción no es válida.', 'in' => 'La fuente de medición no es válida.'];
    }

    public function attributes(): array
    {
        return ['jornada_uuid' => 'jornada', 'uuid_lectura_externa' => 'UUID externo', 'litros_planta' => 'litros medidos en planta', 'recibida_at' => 'fecha de recepción', 'fuente_medicion' => 'fuente de medición', 'observaciones' => 'observaciones'];
    }
}
