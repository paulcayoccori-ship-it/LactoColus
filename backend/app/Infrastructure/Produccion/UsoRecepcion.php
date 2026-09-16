<?php

namespace App\Infrastructure\Produccion;

use App\Infrastructure\Recepciones\RecepcionPlanta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsoRecepcion extends Model
{
    protected $table = 'usos_recepcion';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['litros' => 'decimal:3'];
    }

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(RecepcionPlanta::class, 'recepcion_id');
    }

    public static function occupied(int $id): string
    {
        return bcadd((string) self::where('recepcion_id', $id)->where('estado', '!=', 'liberado')->sum('litros'), '0', 3);
    }
}
