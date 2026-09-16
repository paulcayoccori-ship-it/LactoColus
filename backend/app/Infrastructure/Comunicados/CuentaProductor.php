<?php

namespace App\Infrastructure\Comunicados;

use Illuminate\Database\Eloquent\Model;

class CuentaProductor extends Model
{
    protected $table = 'cuentas_productor';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }
}
