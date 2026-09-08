<?php

declare(strict_types=1);

namespace App\Application\Productores;

use App\Domain\Productores\ProductorRepository;
use Illuminate\Support\Facades\Validator;

final class CrearProductor
{
    public function __construct(private ProductorRepository $repository) {}

    public function handle(ProductorData $data): array
    {
        $attributes = Validator::make($data->attributes, ProductorValidation::rules(), ProductorValidation::messages())->validate();

        return $this->repository->create($attributes + ['estado' => true]);
    }
}
