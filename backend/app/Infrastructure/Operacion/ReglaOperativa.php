<?php

namespace App\Infrastructure\Operacion;

use Illuminate\Database\Eloquent\Model;

class ReglaOperativa extends Model
{
    protected $table = 'reglas_operativas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['valores' => 'array'];
    }
}
