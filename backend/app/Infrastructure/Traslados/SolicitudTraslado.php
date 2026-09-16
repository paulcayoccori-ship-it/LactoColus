<?php

namespace App\Infrastructure\Traslados;

use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Rutas\RutaAcopio;
use Database\Factories\Infrastructure\Traslados\SolicitudTrasladoFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(SolicitudTrasladoFactory::class)]
class SolicitudTraslado extends Model
{
    use HasFactory;

    protected $table = 'solicitudes_traslado';

    protected $guarded = ['id', 'productor_pendiente'];

    protected function casts(): array
    {
        return ['solicitada_at' => 'datetime', 'fecha_efectiva' => 'date', 'decidida_at' => 'datetime', 'aplicada_at' => 'datetime', 'regla_aplicada' => 'array'];
    }

    public function productor(): BelongsTo
    {
        return $this->belongsTo(Productor::class, 'productor_id')->withTrashed();
    }

    public function actual(): BelongsTo
    {
        return $this->belongsTo(RutaAcopio::class, 'ruta_actual_id');
    }

    public function solicitada(): BelongsTo
    {
        return $this->belongsTo(RutaAcopio::class, 'ruta_solicitada_id');
    }
}
