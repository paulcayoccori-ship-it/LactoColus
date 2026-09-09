<?php

namespace App\Infrastructure\Recepciones;

use Database\Factories\Recepciones\ConfiguracionAcopioFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(ConfiguracionAcopioFactory::class)]
class ConfiguracionAcopio extends Model
{
    use HasFactory;

    protected $table = 'configuraciones_acopio';

    protected $fillable = ['clave', 'valor', 'actualizado_por'];

    protected function casts(): array
    {
        return ['valor' => 'decimal:3'];
    }
}
