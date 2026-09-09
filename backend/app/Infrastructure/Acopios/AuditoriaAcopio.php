<?php

namespace App\Infrastructure\Acopios;

use Database\Factories\Acopios\AuditoriaAcopioFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(AuditoriaAcopioFactory::class)]
class AuditoriaAcopio extends Model
{
    use HasFactory;

    protected $table = 'auditorias_acopio';

    protected $fillable = ['jornada_id', 'entrega_id', 'usuario_id', 'accion', 'datos_anteriores', 'datos_nuevos', 'motivo'];

    protected function casts(): array
    {
        return ['datos_anteriores' => 'array', 'datos_nuevos' => 'array'];
    }

    public function jornada(): BelongsTo
    {
        return $this->belongsTo(JornadaAcopio::class, 'jornada_id');
    }

    public function entrega(): BelongsTo
    {
        return $this->belongsTo(EntregaAcopio::class, 'entrega_id');
    }
}
