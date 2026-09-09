<?php

declare(strict_types=1);

namespace App\Infrastructure\Rutas;

use App\Infrastructure\Productores\Productor;
use App\Models\User;
use Database\Factories\RutaAcopioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['codigo', 'nombre', 'descripcion', 'estado', 'recolector_id'])]
#[UseFactory(RutaAcopioFactory::class)]
class RutaAcopio extends Model
{
    use HasFactory;

    protected $table = 'rutas_acopio';

    protected function casts(): array
    {
        return ['estado' => 'boolean'];
    }

    public function recolector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recolector_id');
    }

    public function productores(): BelongsToMany
    {
        return $this->belongsToMany(Productor::class, 'ruta_productor', 'ruta_id', 'productor_id')->withTrashed()->withPivot('orden')->orderByPivot('orden');
    }
}
