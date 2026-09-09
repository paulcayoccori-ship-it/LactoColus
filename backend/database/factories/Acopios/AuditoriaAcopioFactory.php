<?php

namespace Database\Factories\Acopios;

use App\Infrastructure\Acopios\AuditoriaAcopio;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditoriaAcopio>
 */
class AuditoriaAcopioFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['usuario_id' => User::factory(), 'accion' => 'correccion', 'motivo' => 'Prueba'];
    }
}
