<?php

namespace Database\Factories\Infrastructure\Ventas;

use App\Infrastructure\Ventas\Cliente;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ClienteFactory extends Factory
{
    protected $model = Cliente::class;

    public function definition(): array
    {
        return ['uuid' => (string) Str::uuid(), 'nombre' => fake()->name(), 'categoria' => 'publico_general', 'activo' => true];
    }
}
