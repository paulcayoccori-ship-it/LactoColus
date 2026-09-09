<?php

namespace Database\Factories\Recepciones;

use App\Infrastructure\Recepciones\ConfiguracionAcopio;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConfiguracionAcopio>
 */
class ConfiguracionAcopioFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['clave' => 'tolerancia_conciliacion_porcentaje', 'valor' => 2, 'actualizado_por' => User::factory()];
    }
}
