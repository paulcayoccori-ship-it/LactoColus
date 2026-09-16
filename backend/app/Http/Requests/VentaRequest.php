<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administrar-ventas') ?? false;
    }

    public function rules(): array
    {
        return [];
    }

    public function payload(): array
    {
        return $this->only(['uuid', 'cliente_id', 'vendida_at', 'descuento', 'descontar_liquidacion', 'detalles', 'observaciones', 'motivo', 'metodo_pago', 'pagada_at', 'nombre', 'categoria', 'productor_id', 'activo', 'precios', 'limite_proveedor', 'alcance_limite', 'tipo', 'delta']);
    }
}
