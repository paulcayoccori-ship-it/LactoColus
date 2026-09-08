<?php

declare(strict_types=1);

namespace App\Application\Productores;

use App\Domain\Productores\ProductorRepository;

final class EliminarProductor
{
    public function __construct(private ProductorRepository $repository) {}

    public function handle(int $id): void
    {
        $this->repository->delete($id);
    }
}
