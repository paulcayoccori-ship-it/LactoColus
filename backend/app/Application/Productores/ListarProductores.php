<?php

declare(strict_types=1);

namespace App\Application\Productores;

use App\Domain\Productores\ProductorRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListarProductores
{
    public function __construct(private ProductorRepository $repository) {}

    public function handle(string $search = '', ?bool $estado = null, int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->paginate(trim($search), $estado, max(1, min($perPage, 100)));
    }
}
