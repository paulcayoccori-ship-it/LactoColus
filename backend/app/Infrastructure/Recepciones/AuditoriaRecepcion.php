<?php

namespace App\Infrastructure\Recepciones;

use App\Models\User;
use Database\Factories\Recepciones\AuditoriaRecepcionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(AuditoriaRecepcionFactory::class)]
class AuditoriaRecepcion extends Model
{
    use HasFactory;

    protected $table = 'auditorias_recepcion';

    protected $fillable = ['recepcion_id', 'usuario_id', 'accion', 'datos_anteriores', 'datos_nuevos', 'motivo'];

    protected function casts(): array
    {
        return ['datos_anteriores' => 'array', 'datos_nuevos' => 'array'];
    }

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(RecepcionPlanta::class, 'recepcion_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
