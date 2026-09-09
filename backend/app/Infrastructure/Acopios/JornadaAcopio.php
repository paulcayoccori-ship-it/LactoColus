<?php

namespace App\Infrastructure\Acopios;

use App\Infrastructure\Rutas\RutaAcopio;
use App\Models\User;
use Database\Factories\Acopios\JornadaAcopioFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(JornadaAcopioFactory::class)]
class JornadaAcopio extends Model
{
    use HasFactory;

    protected $table = 'jornadas_acopio';

    protected $fillable = ['uuid_publico', 'ruta_id', 'recolector_id', 'fecha_operativa', 'turno', 'iniciada_at', 'cerrada_at', 'estado', 'observaciones'];

    protected function casts(): array
    {
        return ['fecha_operativa' => 'date', 'iniciada_at' => 'datetime', 'cerrada_at' => 'datetime'];
    }

    public function ruta(): BelongsTo
    {
        return $this->belongsTo(RutaAcopio::class, 'ruta_id');
    }

    public function recolector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recolector_id');
    }

    public function entregas(): HasMany
    {
        return $this->hasMany(EntregaAcopio::class, 'jornada_id');
    }
}
