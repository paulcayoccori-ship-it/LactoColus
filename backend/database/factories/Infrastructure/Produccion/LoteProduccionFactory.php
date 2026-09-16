<?php

namespace Database\Factories\Infrastructure\Produccion;

use App\Infrastructure\Produccion\LoteProduccion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LoteProduccionFactory extends Factory
{
    protected $model = LoteProduccion::class;

    public function definition(): array
    {
        return ['uuid' => fake()->uuid(), 'codigo' => fake()->unique()->bothify('LOTE-########'), 'tipo' => 'paria_fresco', 'producido_at' => now(), 'litros_cuba' => '100.000', 'moldes' => 0, 'estado' => 'borrador', 'regla_aplicada' => ['clave' => 'rendimiento', 'version' => 0, 'valores' => ['minimo' => '11.000', 'maximo' => '12.000']], 'responsable_id' => User::factory()];
    }
}
