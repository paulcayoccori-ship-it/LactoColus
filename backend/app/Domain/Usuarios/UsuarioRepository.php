<?php

declare(strict_types=1);

namespace App\Domain\Usuarios;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface UsuarioRepository
{
    public function paginate(string $search, string $role, ?bool $active): LengthAwarePaginator;

    public function roles(): array;

    public function find(int $id): array;

    public function underAdminLock(Closure $operation): mixed;

    public function activeAdminIds(): array;

    public function save(?int $id, array $data): void;
}
