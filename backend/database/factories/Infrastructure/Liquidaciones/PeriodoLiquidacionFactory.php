<?php

namespace Database\Factories\Infrastructure\Liquidaciones;

use App\Infrastructure\Liquidaciones\PeriodoLiquidacion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PeriodoLiquidacion>
 */
class PeriodoLiquidacionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(), 'desde' => '2026-09-03', 'hasta' => '2026-09-09', 'pago_previsto' => '2026-09-11', 'estado' => 'abierto', 'creado_por' => User::factory(), 'motivo' => 'Periodo sintético de prueba',
        ];
    }
}
