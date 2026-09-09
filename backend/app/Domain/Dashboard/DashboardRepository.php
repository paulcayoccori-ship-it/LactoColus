<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

interface DashboardRepository
{
    /** @return array{total:int, activos:int, inactivos:int, usuarios:int, rutas_activas:int, jornadas_abiertas:int, litros_hoy:string, recientes:array} */
    public function summary(): array;
}
