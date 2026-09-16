<?php

namespace App\Infrastructure\Produccion;

use App\Models\User;
use Database\Factories\Infrastructure\Produccion\LoteProduccionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[UseFactory(LoteProduccionFactory::class)]
class LoteProduccion extends Model
{
    use HasFactory;

    protected $table = 'lotes_produccion';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['litros_cuba' => 'decimal:3', 'rendimiento' => 'decimal:6', 'producido_at' => 'datetime', 'regla_aplicada' => 'array'];
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function usos(): HasMany
    {
        return $this->hasMany(UsoRecepcion::class, 'lote_id');
    }

    public function ajustes(): HasMany
    {
        return $this->hasMany(AjusteProduccion::class, 'lote_id');
    }

    public function alerta(): HasOne
    {
        return $this->hasOne(AlertaRendimiento::class, 'lote_id');
    }
}
