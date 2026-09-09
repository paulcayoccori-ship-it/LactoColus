<?php

namespace App\Application\Recepciones;

use App\Domain\Recepciones\RecepcionRepository;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

final class ConsultarRecepciones
{
    public function __construct(private RecepcionRepository $repository) {}

    public function list(int $actorId, array $filters): LengthAwarePaginator
    {
        Gate::forUser(User::findOrFail($actorId))->authorize('administrar-recepciones');

        return $this->repository->paginate($filters);
    }

    public function find(int $actorId, int $id): array
    {
        Gate::forUser(User::findOrFail($actorId))->authorize('administrar-recepciones');

        return $this->repository->find($id);
    }

    public function pendingAlerts(int $actorId): array
    {
        Gate::forUser(User::findOrFail($actorId))->authorize('administrar-recepciones');

        return $this->repository->pendingAlerts();
    }

    public function tolerance(int $actorId): ?string
    {
        Gate::forUser(User::findOrFail($actorId))->authorize('administrar-recepciones');

        return $this->repository->tolerance();
    }
}
