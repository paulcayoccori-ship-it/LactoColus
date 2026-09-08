<?php

declare(strict_types=1);

namespace App\Application\Productores;

final readonly class ProductorData
{
    public function __construct(public array $attributes) {}

    public static function fromArray(array $attributes): self
    {
        return new self(array_intersect_key($attributes, array_flip(['codigo', 'dni', 'nombres', 'apellidos', 'celular', 'email', 'direccion', 'comunidad', 'estado'])));
    }
}
