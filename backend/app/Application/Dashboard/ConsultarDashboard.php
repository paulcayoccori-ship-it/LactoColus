<?php

declare(strict_types=1);

namespace App\Application\Dashboard;

use App\Domain\Dashboard\DashboardRepository;
use Illuminate\Support\Facades\Gate;

final class ConsultarDashboard
{
    public function __construct(private DashboardRepository $repository) {}

    public function handle(): array
    {
        Gate::authorize('ver-dashboard');

        return $this->repository->summary();
    }
}
