<?php

namespace Database\Factories\Acopios;

use App\Infrastructure\Acopios\EntregaAcopio;
use App\Infrastructure\Acopios\JornadaAcopio;
use App\Infrastructure\Productores\Productor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EntregaAcopio>
 */
class EntregaAcopioFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['uuid_cliente' => fake()->uuid(), 'jornada_id' => JornadaAcopio::factory(), 'ruta_id' => fn (array $attributes) => JornadaAcopio::query()->find($attributes['jornada_id'])?->ruta_id, 'productor_id' => Productor::factory(), 'recolector_id' => null, 'litros' => fake()->randomFloat(3, 1, 30), 'recolectada_at' => now(), 'sincronizada_at' => now()];
    }
}
