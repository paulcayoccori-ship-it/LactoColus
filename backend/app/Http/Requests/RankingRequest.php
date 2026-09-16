<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RankingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administrar-ranking') ?? false;
    }

    public function rules(): array
    {
        return [];
    }

    public function payload(): array
    {
        return $this->only(['activo', 'privacidad', 'agregacion', 'inicio_semana', 'motivo', 'bandas', 'tipo', 'fecha', 'ruta_id']);
    }
}
