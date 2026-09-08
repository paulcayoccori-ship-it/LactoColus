<?php

declare(strict_types=1);

namespace App\Infrastructure\Productores;

use Database\Factories\ProductorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['codigo', 'dni', 'nombres', 'apellidos', 'celular', 'email', 'direccion', 'comunidad', 'estado'])]
#[UseFactory(ProductorFactory::class)]
class Productor extends Model
{
    /** @use HasFactory<ProductorFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'productores';

    protected function casts(): array
    {
        return ['estado' => 'boolean'];
    }
}
