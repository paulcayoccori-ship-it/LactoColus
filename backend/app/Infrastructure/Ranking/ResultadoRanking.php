<?php

namespace App\Infrastructure\Ranking;

use Illuminate\Database\Eloquent\Model;

class ResultadoRanking extends Model
{
    protected $table = 'resultados_ranking';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['productor_snapshot' => 'array', 'rutas_snapshot' => 'array', 'detalle' => 'array', 'puntuacion' => 'decimal:4', 'posicion' => 'integer'];
    }
}
