<?php

namespace Database\Factories\Infrastructure\Calidad;

use App\Infrastructure\Calidad\PerfilCalidad;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PerfilCalidadFactory extends Factory
{
    protected $model = PerfilCalidad::class;

    public function definition(): array
    {
        return ['version' => fake()->unique()->numberBetween(1, 100000), 'nombre' => 'Perfil incompleto de prueba', 'activo' => false, 'vigente_desde' => '2026-01-01', 'criterios' => [], 'creado_por' => User::factory()];
    }
}
