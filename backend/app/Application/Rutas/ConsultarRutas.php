<?php

declare(strict_types=1);

namespace App\Application\Rutas;

use App\Domain\Rutas\RutaRepository;
use Illuminate\Support\Facades\Gate;

final class ConsultarRutas
{
    public function __construct(private RutaRepository $repository) {}

    public function handle(string $search, string $estado, ?int $id): array
    {
        Gate::authorize('administrar-rutas');

        return ['rutas' => $this->repository->paginate(trim($search), in_array($estado, ['0', '1'], true) ? $estado === '1' : null), 'detalle' => $id ? $this->find($id) : null] + ($id ? $this->repository->options() : ['recolectores' => [], 'disponibles' => []]);
    }

    public function find(int $id): array
    {
        Gate::authorize('administrar-rutas');

        return $this->repository->find($id);
    }
}
