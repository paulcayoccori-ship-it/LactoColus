<?php

namespace App\Http\Requests;

use App\Domain\Calidad\ParametrosCalidad;
use Illuminate\Foundation\Http\FormRequest;

class CalidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('operar-calidad') ?? false;
    }

    public function rules(): array
    {
        return ['uuid_externo' => ['required', 'uuid']];
    }

    public function messages(): array
    {
        return ParametrosCalidad::messages();
    }

    /** El caso de uso valida el contenido después de resolver la idempotencia. */
    public function analysisInput(): array
    {
        return $this->only(array_keys(ParametrosCalidad::rules()));
    }
}
