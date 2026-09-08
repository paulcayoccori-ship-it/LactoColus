<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexProductorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:150'], 'estado' => ['sometimes', 'boolean'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return ['boolean' => 'El campo :attribute debe ser 0 o 1.', 'integer' => 'El campo :attribute debe ser un entero.', 'min' => 'El campo :attribute debe ser al menos :min.', 'max' => 'El campo :attribute no debe superar :max.', 'string' => 'El campo :attribute debe ser texto.'];
    }
}
