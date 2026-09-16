<?php

namespace App\Infrastructure\Inventario;

use Illuminate\Database\Eloquent\Model;

class MovimientoInventario extends Model
{
    protected $table = 'movimientos_inventario';

    protected $guarded = ['id'];
}
