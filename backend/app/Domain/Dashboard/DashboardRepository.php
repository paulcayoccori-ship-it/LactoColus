<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

interface DashboardRepository
{
    /** @return array{total:int, activos:int, inactivos:int, usuarios:int, recientes:array} */
    public function summary(): array;
}
