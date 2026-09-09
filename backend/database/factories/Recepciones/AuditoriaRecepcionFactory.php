<?php

namespace Database\Factories\Recepciones;

use App\Infrastructure\Recepciones\AuditoriaRecepcion;
use App\Infrastructure\Recepciones\RecepcionPlanta;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditoriaRecepcion>
 */
class AuditoriaRecepcionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['recepcion_id' => RecepcionPlanta::factory(), 'usuario_id' => User::factory(), 'accion' => 'correccion', 'motivo' => 'Prueba'];
    }
}
