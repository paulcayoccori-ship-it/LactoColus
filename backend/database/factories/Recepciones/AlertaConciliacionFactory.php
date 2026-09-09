<?php

namespace Database\Factories\Recepciones;

use App\Infrastructure\Recepciones\AlertaConciliacion;
use App\Infrastructure\Recepciones\RecepcionPlanta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertaConciliacion>
 */
class AlertaConciliacionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['recepcion_id' => RecepcionPlanta::factory(), 'litros_campo' => 10, 'litros_planta' => 15, 'diferencia_litros' => 5, 'diferencia_porcentaje' => 50, 'estado' => 'pendiente'];
    }
}
