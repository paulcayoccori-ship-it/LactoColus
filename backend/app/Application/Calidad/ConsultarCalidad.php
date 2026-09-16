<?php

namespace App\Application\Calidad;

use App\Domain\Calidad\CalidadRepository;
use App\Infrastructure\Calidad\AnalisisCalidad;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

final class ConsultarCalidad
{
    public function __construct(private CalidadRepository $repository) {}

    private function scope(int $actorId, bool $admin = false): ?int
    {
        $user = User::findOrFail($actorId);
        Gate::forUser($user)->authorize($admin ? 'administrar-calidad' : 'operar-calidad');

        return $user->isActiveAdministrator() ? null : $actorId;
    }

    public function listing(int $actorId, array $filters): LengthAwarePaginator
    {
        return $this->repository->listing($this->scope($actorId), $filters);
    }

    public function find(int $actorId, string $uuid): AnalisisCalidad
    {
        return $this->repository->find($this->scope($actorId), $uuid);
    }

    public function jornadas(int $actorId, array $filters): Collection
    {
        Gate::forUser(User::findOrFail($actorId))->authorize('operar-calidad');

        return $this->repository->jornadas($filters);
    }

    public function producers(int $actorId, string $search = ''): LengthAwarePaginator
    {
        return $this->repository->producers($this->scope($actorId), $search);
    }

    public function options(int $actorId, ?int $producerId = null): array
    {
        return $this->repository->options($this->scope($actorId), $producerId);
    }

    public function profiles(int $actorId): LengthAwarePaginator
    {
        return $this->repository->profiles($this->scope($actorId, true));
    }

    public function profile(int $actorId, int $id): array
    {
        return $this->repository->profile($this->scope($actorId, true), $id);
    }
}
