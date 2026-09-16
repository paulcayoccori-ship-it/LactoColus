<?php

namespace App\Infrastructure\Ranking;

use Database\Factories\Infrastructure\Ranking\CalculoRankingFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(CalculoRankingFactory::class)]
class CalculoRanking extends Model
{
    use HasFactory;

    protected $table = 'calculos_ranking';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['desde' => 'date', 'hasta' => 'date', 'regla_aplicada' => 'array', 'vigente' => 'boolean'];
    }

    public function resultados(): HasMany
    {
        return $this->hasMany(ResultadoRanking::class, 'calculo_id');
    }
}
