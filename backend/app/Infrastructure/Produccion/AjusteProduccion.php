<?php

namespace App\Infrastructure\Produccion;

use Illuminate\Database\Eloquent\Model;

class AjusteProduccion extends Model
{
    protected $table = 'ajustes_produccion';

    protected $guarded = ['id'];
}
