<?php

namespace App\Domain\Recepciones;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface RecepcionRepository
{
    public function paginate(array $filters): LengthAwarePaginator;

    public function find(int $id): array;

    public function pendingAlerts(): array;

    public function tolerance(): ?string;

    public function saveTolerance(string $value, int $userId): void;

    public function byExternalUuid(string $uuid): ?array;

    public function create(array $data): array;

    public function update(int $id, array $data): array;

    public function updateAlert(int $receptionId, array $data): void;

    public function resolveAlert(int $alertId, int $userId, string $comment): void;
}
