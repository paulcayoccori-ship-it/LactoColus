<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TrasladoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isActiveAdministrator() || ($this->user()?->active && $this->user()?->hasRole('productor', 'web'));
    }

    public function rules(): array
    {
        return [];
    }

    public function payload(): array
    {
        return $this->only(['uuid', 'productor_id', 'ruta_solicitada_id', 'fecha_efectiva', 'motivo', 'comentario', 'anticipacion_dias']);
    }
}
