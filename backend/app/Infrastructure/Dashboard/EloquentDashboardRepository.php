<?php

declare(strict_types=1);

namespace App\Infrastructure\Dashboard;

use App\Domain\Dashboard\DashboardRepository;
use App\Infrastructure\Productores\Productor;
use App\Models\User;

final class EloquentDashboardRepository implements DashboardRepository
{
    public function summary(): array
    {
        $counts = Productor::query()->selectRaw('COUNT(*) AS total, COALESCE(SUM(CASE WHEN estado = 1 THEN 1 ELSE 0 END), 0) AS activos')->first();

        return ['total' => (int) $counts->total, 'activos' => (int) $counts->activos,
            'inactivos' => (int) $counts->total - (int) $counts->activos, 'usuarios' => User::query()->count(),
            'recientes' => Productor::query()->latest('created_at')->orderByDesc('id')->limit(5)->get(['id', 'codigo', 'nombres', 'apellidos', 'estado', 'created_at'])->toArray()];
    }
}
