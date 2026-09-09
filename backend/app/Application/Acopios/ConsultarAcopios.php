<?php

namespace App\Application\Acopios;

use App\Domain\Acopios\AcopioRepository;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

final class ConsultarAcopios
{
    public function __construct(private AcopioRepository $repository) {}

    public function adminList(int $actorId, array $filters): LengthAwarePaginator
    {
        Gate::forUser(User::findOrFail($actorId))->authorize('administrar-acopios');

        return $this->repository->adminPaginate($filters);
    }

    public function adminFind(int $actorId, int $id): array
    {
        Gate::forUser(User::findOrFail($actorId))->authorize('administrar-acopios');

        return $this->repository->adminFind($id);
    }

    public function routesForCollector(int $actorId): array
    {
        $user = User::findOrFail($actorId);
        abort_unless($user->active && $user->hasRole('recolector', 'web'), 403);

        return $this->repository->routesForCollector($actorId);
    }

    public function journeyForCollector(int $actorId, string $uuid): array
    {
        $user = User::findOrFail($actorId);
        abort_unless($user->active && $user->hasRole('recolector', 'web'), 403);
        $journey = $this->repository->findJourneyByPublicId($uuid, $actorId);
        abort_if($journey === null, 404);

        return $journey;
    }
}
