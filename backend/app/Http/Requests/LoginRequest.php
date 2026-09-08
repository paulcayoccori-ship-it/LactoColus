<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:255'], 'device_name' => ['sometimes', 'string', 'max:100']];
    }

    public function messages(): array
    {
        return ['required' => 'El campo :attribute es obligatorio.', 'email' => 'Ingresa un correo electrónico válido.', 'string' => 'El campo :attribute debe ser texto.', 'max' => 'El campo :attribute no debe superar :max caracteres.'];
    }
}
