<?php

namespace App\Infrastructure\Recepciones;

use App\Infrastructure\Acopios\JornadaAcopio;
use App\Infrastructure\Rutas\RutaAcopio;
use App\Models\User;
use Database\Factories\Recepciones\RecepcionPlantaFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[UseFactory(RecepcionPlantaFactory::class)]
class RecepcionPlanta extends Model
{
    use HasFactory;

    protected $table = 'recepciones_planta';

    protected $fillable = ['uuid_publico', 'uuid_lectura_externa', 'jornada_id', 'ruta_id', 'recolector_id', 'litros_campo', 'litros_planta', 'diferencia_litros', 'diferencia_porcentaje', 'tolerancia_porcentaje', 'resultado', 'recibida_at', 'fuente_medicion', 'observaciones', 'registrada_por'];

    protected function casts(): array
    {
        return ['litros_campo' => 'decimal:3', 'litros_planta' => 'decimal:3', 'diferencia_litros' => 'decimal:3', 'diferencia_porcentaje' => 'decimal:3', 'tolerancia_porcentaje' => 'decimal:3', 'recibida_at' => 'datetime'];
    }

    public function jornada(): BelongsTo
    {
        return $this->belongsTo(JornadaAcopio::class, 'jornada_id');
    }

    public function ruta(): BelongsTo
    {
        return $this->belongsTo(RutaAcopio::class, 'ruta_id');
    }

    public function recolector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recolector_id');
    }

    public function registradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrada_por');
    }

    public function alerta(): HasOne
    {
        return $this->hasOne(AlertaConciliacion::class, 'recepcion_id');
    }
}
