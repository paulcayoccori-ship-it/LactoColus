<?php

namespace App\Infrastructure\Ventas;

use Database\Factories\Infrastructure\Ventas\ClienteFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(ClienteFactory::class)]
class Cliente extends Model
{
    use HasFactory;

    protected $table = 'clientes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
