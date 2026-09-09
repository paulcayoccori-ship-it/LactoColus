<?php

declare(strict_types=1);

namespace App\Domain\Rutas;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface RutaRepository
{
    public function paginate(string $search, ?bool $estado): LengthAwarePaginator;

    public function find(int $id, bool $lock = false): array;

    public function options(): array;

    public function save(?int $id, array $data): int;

    public function collector(int $id): ?array;

    public function producer(int $id): ?array;

    public function assignment(int $productorId): ?array;

    public function attach(int $rutaId, int $productorId, int $orden): void;

    public function detach(int $rutaId, int $productorId): void;

    public function reorder(int $rutaId, array $ids): void;
}
