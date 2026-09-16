<?php

namespace App\Infrastructure\Penalizaciones;

use App\Infrastructure\Productores\Productor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsistenciaTecnica extends Model
{
    protected $table = 'asistencias_tecnicas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['criterio_aplicado' => 'array', 'requiere_revision' => 'boolean', 'fuente_vigente' => 'boolean', 'fecha_at' => 'datetime'];
    }

    public function productor(): BelongsTo
    {
        return $this->belongsTo(Productor::class, 'productor_id')->withTrashed();
    }
}
