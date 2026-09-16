<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ComunicadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administrar-comunicados') ?? false;
    }

    public function rules(): array
    {
        return [];
    }

    public function payload(): array
    {
        return $this->only(['uuid', 'titulo', 'contenido', 'tipo', 'audiencia', 'ruta_id', 'productores', 'roles', 'publicar_at', 'vence_at', 'motivo', 'usuario_id', 'productor_id', 'activa']);
    }
}
