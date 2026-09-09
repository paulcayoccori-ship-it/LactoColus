<?php

namespace App\Infrastructure\Recepciones;

use App\Models\User;
use Database\Factories\Recepciones\AlertaConciliacionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(AlertaConciliacionFactory::class)]
class AlertaConciliacion extends Model
{
    use HasFactory;

    protected $table = 'alertas_conciliacion';

    protected $fillable = ['recepcion_id', 'litros_campo', 'litros_planta', 'diferencia_litros', 'diferencia_porcentaje', 'estado', 'revisada_por', 'revisada_at', 'comentario_resolucion'];

    protected function casts(): array
    {
        return ['litros_campo' => 'decimal:3', 'litros_planta' => 'decimal:3', 'diferencia_litros' => 'decimal:3', 'diferencia_porcentaje' => 'decimal:3', 'revisada_at' => 'datetime'];
    }

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(RecepcionPlanta::class, 'recepcion_id');
    }

    public function revisadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisada_por');
    }
}
