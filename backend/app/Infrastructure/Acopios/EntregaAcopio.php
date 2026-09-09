<?php

namespace App\Infrastructure\Acopios;

use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Rutas\RutaAcopio;
use App\Models\User;
use Database\Factories\Acopios\EntregaAcopioFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(EntregaAcopioFactory::class)]
class EntregaAcopio extends Model
{
    use HasFactory;

    protected $table = 'entregas_acopio';

    protected $fillable = ['uuid_cliente', 'jornada_id', 'ruta_id', 'productor_id', 'recolector_id', 'litros', 'recolectada_at', 'observacion', 'sincronizada_at'];

    protected function casts(): array
    {
        return ['litros' => 'decimal:3', 'recolectada_at' => 'datetime', 'sincronizada_at' => 'datetime'];
    }

    public function jornada(): BelongsTo
    {
        return $this->belongsTo(JornadaAcopio::class, 'jornada_id');
    }

    public function ruta(): BelongsTo
    {
        return $this->belongsTo(RutaAcopio::class, 'ruta_id');
    }

    public function productor(): BelongsTo
    {
        return $this->belongsTo(Productor::class, 'productor_id')->withTrashed();
    }

    public function recolector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recolector_id');
    }
}
