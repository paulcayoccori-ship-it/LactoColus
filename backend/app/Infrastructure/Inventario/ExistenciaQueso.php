<?php

namespace App\Infrastructure\Inventario;

use Illuminate\Database\Eloquent\Model;

class ExistenciaQueso extends Model
{
    protected $table = 'existencias_queso';

    protected $primaryKey = 'tipo';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['moldes' => 'integer'];
    }
}
