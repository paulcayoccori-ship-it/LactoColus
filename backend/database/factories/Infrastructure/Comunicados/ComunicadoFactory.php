<?php

namespace Database\Factories\Infrastructure\Comunicados;

use App\Infrastructure\Comunicados\Comunicado;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ComunicadoFactory extends Factory
{
    protected $model = Comunicado::class;

    public function definition(): array
    {
        return ['uuid' => (string) Str::uuid(), 'titulo' => fake()->sentence(), 'contenido' => fake()->paragraph(), 'tipo' => 'general', 'audiencia' => 'roles', 'roles_destino' => ['recolector'], 'publicar_at' => now()->subMinute(), 'estado' => 'borrador', 'autor_id' => User::factory()];
    }
}
