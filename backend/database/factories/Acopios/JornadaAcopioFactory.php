<?php

namespace Database\Factories\Acopios;

use App\Infrastructure\Acopios\JornadaAcopio;
use App\Infrastructure\Rutas\RutaAcopio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JornadaAcopio>
 */
class JornadaAcopioFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['uuid_publico' => fake()->uuid(), 'ruta_id' => RutaAcopio::factory(), 'recolector_id' => null, 'fecha_operativa' => fake()->date(), 'turno' => 'primera_vuelta', 'estado' => 'abierta'];
    }
}
