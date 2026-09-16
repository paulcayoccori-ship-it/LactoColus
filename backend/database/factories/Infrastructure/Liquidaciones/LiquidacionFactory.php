<?php

namespace Database\Factories\Infrastructure\Liquidaciones;

use App\Infrastructure\Liquidaciones\Liquidacion;
use App\Infrastructure\Liquidaciones\PeriodoLiquidacion;
use App\Infrastructure\Productores\Productor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Liquidacion>
 */
class LiquidacionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(), 'periodo_id' => PeriodoLiquidacion::factory(), 'productor_id' => Productor::factory(), 'productor_snapshot' => ['codigo' => 'PRUEBA', 'nombres' => 'Productor', 'apellidos' => 'Prueba'], 'litros_diarios' => ['2026-09-03' => '10.000'], 'litros_total' => '10.000', 'precio_litro' => '1.70', 'importe_bruto' => '17.00', 'penalizaciones' => '0.00', 'descuentos_queso' => '0.00', 'total_base' => '17.00', 'regla_aplicada' => ['version' => 0, 'valores' => config('operacion.liquidaciones')], 'detalle_calculo' => [], 'estado' => 'calculada',
        ];
    }
}
