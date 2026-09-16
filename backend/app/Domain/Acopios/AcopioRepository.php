<?php

namespace App\Domain\Acopios;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface AcopioRepository
{
    public function adminPaginate(array $filters): LengthAwarePaginator;

    public function adminFind(int $id): array;

    public function routesForCollector(int $userId): array;

    public function journeysForCollector(int $userId, array $filters, int $perPage): LengthAwarePaginator;

    public function findJourneyByPublicId(string $uuid, ?int $userId = null): ?array;

    public function createJourney(int $routeId, array $data): array;

    public function updateJourney(int $id, array $data): array;

    public function addDelivery(int $userId, array $data): array;

    public function updateDelivery(int $id, array $data): array;

    /** Ids de productores activos de la ruta sin entrega real registrada en esa jornada. */
    public function missingDeliveries(int $routeId, int $jornadaId): array;
}
