<?php

namespace App\Infrastructure\Calidad;

use App\Domain\Calidad\ParametrosCalidad;
use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Rutas\RutaAcopio;
use App\Models\User;
use Database\Factories\Infrastructure\Calidad\AnalisisCalidadFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(AnalisisCalidadFactory::class)]
class AnalisisCalidad extends Model
{
    use HasFactory;

    protected $table = 'analisis_calidad';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return array_fill_keys(array_keys(ParametrosCalidad::CAMPOS), 'decimal:4') + ['muestra_at' => 'datetime', 'sincronizada_at' => 'datetime', 'limites_aplicados' => 'array', 'advertencias' => 'array'];
    }

    public function productor(): BelongsTo
    {
        return $this->belongsTo(Productor::class)->withTrashed();
    }

    public function ruta(): BelongsTo
    {
        return $this->belongsTo(RutaAcopio::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function auditorias(): HasMany
    {
        return $this->hasMany(AuditoriaCalidad::class, 'analisis_id');
    }
}
