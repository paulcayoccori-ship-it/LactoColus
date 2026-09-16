<?php

namespace Database\Factories\Infrastructure\Ranking;

use App\Infrastructure\Ranking\CalculoRanking;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CalculoRankingFactory extends Factory
{
    protected $model = CalculoRanking::class;

    public function definition(): array
    {
        return ['uuid' => (string) Str::uuid(), 'huella' => hash('sha256', (string) Str::uuid()), 'tipo' => 'diario', 'desde' => today(), 'hasta' => today(), 'regla_aplicada' => [], 'algoritmo' => 'bandas_ponderadas_v1', 'usuario_id' => User::factory(), 'vigente' => true];
    }
}
