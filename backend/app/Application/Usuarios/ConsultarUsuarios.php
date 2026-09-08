<?php

declare(strict_types=1);

namespace App\Application\Usuarios;

use App\Domain\Usuarios\UsuarioRepository;
use Illuminate\Support\Facades\Gate;

final class ConsultarUsuarios
{
    public function __construct(private UsuarioRepository $repository) {}

    public function handle(string $search = '', string $role = '', string $active = ''): array
    {
        Gate::authorize('administrar-usuarios');

        return ['usuarios' => $this->repository->paginate(mb_substr(trim($search), 0, 150), $role, match ($active) {
            '1' => true, '0' => false, default => null
        }), 'roles' => $this->repository->roles()];
    }

    public function find(int $id): array
    {
        Gate::authorize('administrar-usuarios');

        return $this->repository->find($id);
    }
}
