<?php

namespace App\Infrastructure\Calidad;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditoriaCalidad extends Model
{
    protected $table = 'auditorias_calidad';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['anteriores' => 'array', 'nuevos' => 'array'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
