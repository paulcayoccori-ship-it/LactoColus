<?php

declare(strict_types=1);

namespace App\Application\Productores;

use App\Domain\Productores\ProductorRepository;
use Illuminate\Support\Facades\Validator;

final class ActualizarProductor
{
    public function __construct(private ProductorRepository $repository) {}

    public function handle(int $id, ProductorData $data): array
    {
        $this->repository->find($id);
        $attributes = Validator::make($data->attributes, ProductorValidation::rules($id, true), ProductorValidation::messages())->validate();

        return $this->repository->update($id, $attributes);
    }
}
