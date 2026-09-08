<?php

declare(strict_types=1);

namespace App\Application\Productores;

use Illuminate\Validation\Rule;

final class ProductorValidation
{
    public static function rules(?int $id = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'codigo' => [$required, 'required', 'string', 'max:50', Rule::unique('productores', 'codigo')->ignore($id)],
            'dni' => [$required, 'required', 'string', 'regex:/^[0-9]{8}$/D', Rule::unique('productores', 'dni')->ignore($id)],
            'nombres' => [$required, 'required', 'string', 'max:150'],
            'apellidos' => [$required, 'required', 'string', 'max:150'],
            'celular' => ['nullable', 'string', 'regex:/^[0-9]{9}$/D'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('productores', 'email')->ignore($id)],
            'direccion' => ['nullable', 'string', 'max:255'],
            'comunidad' => ['nullable', 'string', 'max:150'],
            'estado' => ['sometimes', 'boolean'],
        ];
    }

    public static function messages(): array
    {
        return ['required' => 'El campo :attribute es obligatorio.', 'string' => 'El campo :attribute debe ser texto.',
            'max' => 'El campo :attribute no debe superar :max caracteres.', 'unique' => 'El campo :attribute ya está registrado.',
            'email' => 'Ingresa un correo electrónico válido.', 'boolean' => 'El estado debe ser activo o inactivo.',
            'dni.regex' => 'El DNI debe tener exactamente 8 dígitos.', 'celular.regex' => 'El celular debe tener exactamente 9 dígitos.'];
    }
}
