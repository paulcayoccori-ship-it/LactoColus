<?php

namespace Database\Factories;

use App\Infrastructure\Rutas\RutaAcopio;
use Illuminate\Database\Eloquent\Factories\Factory;

class RutaAcopioFactory extends Factory
{
    protected $model = RutaAcopio::class;

    public function definition(): array
    {
        return ['codigo' => fake()->unique()->bothify('R-########'), 'nombre' => fake()->city(), 'descripcion' => null, 'estado' => true];
    }
}
