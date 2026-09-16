<?php

namespace App\Infrastructure\Penalizaciones;

use App\Infrastructure\Productores\Productor;
use Database\Factories\Infrastructure\Penalizaciones\SancionCalidadFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(SancionCalidadFactory::class)]
class SancionCalidad extends Model
{
    use HasFactory;

    protected $table = 'sanciones_calidad';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['agua_anadida' => 'decimal:4', 'tarifa_penalizada' => 'decimal:2', 'regla_aplicada' => 'array', 'fuente_snapshot' => 'array', 'requiere_revision' => 'boolean', 'propuesta_perdida' => 'boolean', 'propuesta_expulsion' => 'boolean', 'decidida_at' => 'datetime'];
    }

    public function productor(): BelongsTo
    {
        return $this->belongsTo(Productor::class, 'productor_id')->withTrashed();
    }
}
