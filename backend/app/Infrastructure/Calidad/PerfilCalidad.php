<?php

namespace App\Infrastructure\Calidad;

use Database\Factories\Infrastructure\Calidad\PerfilCalidadFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(PerfilCalidadFactory::class)]
class PerfilCalidad extends Model
{
    use HasFactory;

    protected $table = 'perfiles_calidad';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['criterios' => 'array', 'activo' => 'boolean'];
    }
}
