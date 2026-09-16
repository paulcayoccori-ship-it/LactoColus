<?php

namespace App\Infrastructure\Comunicados;

use App\Models\User;
use Database\Factories\Infrastructure\Comunicados\ComunicadoFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(ComunicadoFactory::class)]
class Comunicado extends Model
{
    use HasFactory;

    protected $table = 'comunicados';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['roles_destino' => 'array', 'publicar_at' => 'datetime', 'vence_at' => 'datetime'];
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_id');
    }

    public function visibleState(): string
    {
        if (in_array($this->estado, ['programado', 'publicado'], true)) {
            if ($this->vence_at?->lte(now())) {
                return 'vencido';
            } if ($this->publicar_at->lte(now())) {
                return 'publicado';
            }
        }

        return $this->estado;
    }
}
