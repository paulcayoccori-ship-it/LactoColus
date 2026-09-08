<?php

declare(strict_types=1);

namespace App\Domain\Productores;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ProductorRepository
{
    public function paginate(string $search, ?bool $estado, int $perPage): LengthAwarePaginator;

    public function find(int $id): array;

    public function create(array $attributes): array;

    public function update(int $id, array $attributes): array;

    public function delete(int $id): void;
}
