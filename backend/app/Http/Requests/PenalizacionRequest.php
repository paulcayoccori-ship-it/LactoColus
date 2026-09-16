<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PenalizacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administrar-penalizaciones') ?? false;
    }

    public function rules(): array
    {
        return [];
    }

    public function payload(): array
    {
        return $this->only(['activo', 'tarifa_primera', 'tarifa_grave', 'alcance_tarifa', 'unidad_falta', 'ventana_dias', 'motivo', 'decision', 'decision_perdida', 'decision_expulsion', 'estado', 'responsable_id', 'fecha_at', 'observaciones']);
    }
}
