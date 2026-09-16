<?php

namespace App\Domain\Calidad;

final class ParametrosCalidad
{
    /** Límites de captura, no criterios de conformidad ni rangos sanitarios. */
    public const CAMPOS = [
        'grasa' => ['Grasa', '% m/m', '100'],
        'proteina' => ['Proteína', '% m/m', '100'],
        'lactosa' => ['Lactosa', '% m/m', '100'],
        'densidad_medida' => ['Densidad medida', 'g/mL', '2'],
        'temperatura' => ['Temperatura de muestra', '°C', '100'],
        'densidad_corregida' => ['Densidad corregida', 'g/mL', '2'],
        'solidos_totales' => ['Sólidos totales medidos (opcional)', '% m/m', '100'],
        'solidos_no_grasos' => ['Sólidos no grasos', '% m/m', '100'],
        'ph' => ['pH', 'pH', '14'],
        'acidez' => ['Acidez titulable', '% ácido láctico', '100'],
        'agua_anadida' => ['Agua añadida', '%', '100'],
    ];

    public static function criteria(): array
    {
        return array_diff_key(self::CAMPOS, ['densidad_medida' => true, 'solidos_totales' => true]);
    }

    public static function rules(bool $relations = true): array
    {
        $rules = ['muestra_at' => ['required', 'date', 'before_or_equal:now'], 'equipo' => ['nullable', 'string', 'max:100'], 'fuente' => ['required', 'in:manual,dispositivo'], 'observaciones' => ['nullable', 'string', 'max:5000']];
        foreach (self::CAMPOS as $key => $definition) {
            $rules[$key] = [in_array($key, ['densidad_corregida', 'solidos_totales'], true) ? 'nullable' : 'required', 'numeric', 'decimal:0,4', 'min:0', 'max:'.$definition[2]];
            if (in_array($key, ['densidad_medida', 'densidad_corregida'], true)) {
                $rules[$key][] = 'gt:0';
            }
        }
        if ($relations) {
            $rules += ['uuid_externo' => ['required', 'uuid'], 'productor_id' => ['required', 'integer'], 'jornada_id' => ['nullable', 'integer'], 'entrega_id' => ['nullable', 'integer'], 'ruta_id' => ['prohibited'], 'responsable_id' => ['prohibited'], 'estado' => ['prohibited'], 'limites_aplicados' => ['prohibited']];
        }

        return $rules;
    }

    public static function messages(): array
    {
        return ['gt' => 'La densidad debe ser mayor que cero.', 'required' => 'El campo :attribute es obligatorio.', 'numeric' => 'El campo :attribute debe ser numérico.', 'decimal' => 'Usa como máximo cuatro decimales en :attribute.', 'min' => 'El campo :attribute no puede ser negativo.', 'max' => 'El campo :attribute supera el límite de captura (:max).', 'date' => 'La fecha no es válida.', 'before_or_equal' => 'La muestra no puede estar en el futuro.', 'uuid' => 'El UUID externo no es válido.', 'integer' => 'La referencia :attribute no es válida.', 'in' => 'El valor de :attribute no es válido.', 'prohibited' => 'El campo :attribute se determina en el servidor.', 'string' => 'El campo :attribute debe ser texto.'];
    }

    public static function attributes(): array
    {
        return array_map(fn (array $field): string => $field[0], self::CAMPOS) + ['muestra_at' => 'fecha de muestra', 'productor_id' => 'productor', 'uuid_externo' => 'UUID externo'];
    }
}
