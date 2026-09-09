<?php

declare(strict_types=1);

namespace App\Infrastructure\Dashboard;

use App\Domain\Dashboard\DashboardRepository;
use App\Infrastructure\Acopios\EntregaAcopio;
use App\Infrastructure\Acopios\JornadaAcopio;
use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Recepciones\AlertaConciliacion;
use App\Infrastructure\Recepciones\RecepcionPlanta;
use App\Infrastructure\Rutas\RutaAcopio;
use App\Models\User;

final class EloquentDashboardRepository implements DashboardRepository
{
    public function summary(): array
    {
        $counts = Productor::query()->selectRaw('COUNT(*) AS total, COALESCE(SUM(CASE WHEN estado = 1 THEN 1 ELSE 0 END), 0) AS activos')->first();

        return ['rutas_activas' => RutaAcopio::query()->where('estado', true)->count(), 'jornadas_abiertas' => JornadaAcopio::query()->where('estado', 'abierta')->count(), 'litros_hoy' => (string) (EntregaAcopio::query()->whereDate('recolectada_at', today())->sum('litros') ?: '0.000'), 'recepciones_hoy' => RecepcionPlanta::query()->whereDate('recibida_at', today())->where('resultado', '!=', 'anulada')->count(), 'litros_recibidos_hoy' => number_format((float) RecepcionPlanta::query()->whereDate('recibida_at', today())->where('resultado', '!=', 'anulada')->sum('litros_planta'), 3, '.', ''), 'alertas_pendientes' => AlertaConciliacion::query()->where('estado', 'pendiente')->count(), 'total' => (int) $counts->total, 'activos' => (int) $counts->activos,
            'inactivos' => (int) $counts->total - (int) $counts->activos, 'usuarios' => User::query()->count(),
            'recientes' => Productor::query()->latest('created_at')->orderByDesc('id')->limit(5)->get(['id', 'codigo', 'nombres', 'apellidos', 'estado', 'created_at'])->toArray()];
    }
}
