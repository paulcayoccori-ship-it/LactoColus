<?php

namespace App\Domain\Calidad;

use App\Infrastructure\Calidad\AnalisisCalidad;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface CalidadRepository
{
    public function listing(?int $ownerId, array $filters): LengthAwarePaginator;

    public function jornadas(array $filters): Collection;

    public function find(?int $ownerId, string $uuid): AnalisisCalidad;

    public function producers(?int $ownerId, string $search = ''): LengthAwarePaginator;

    public function options(?int $ownerId, ?int $producerId = null): array;

    public function profiles(?int $ownerId): LengthAwarePaginator;

    public function profile(?int $ownerId, int $id): array;
}
