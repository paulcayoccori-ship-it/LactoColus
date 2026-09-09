<?php

namespace Database\Factories\Recepciones;

use App\Infrastructure\Acopios\JornadaAcopio;
use App\Infrastructure\Recepciones\RecepcionPlanta;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecepcionPlanta>
 */
class RecepcionPlantaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['uuid_publico' => fake()->uuid(), 'uuid_lectura_externa' => fake()->uuid(), 'jornada_id' => JornadaAcopio::factory(), 'ruta_id' => fn (array $attributes) => JornadaAcopio::query()->find($attributes['jornada_id'])?->ruta_id, 'recolector_id' => null, 'litros_campo' => 10, 'litros_planta' => 10, 'diferencia_litros' => 0, 'diferencia_porcentaje' => 0, 'tolerancia_porcentaje' => 2, 'resultado' => 'dentro_tolerancia', 'recibida_at' => now(), 'fuente_medicion' => 'manual', 'registrada_por' => User::factory()];
    }
}
