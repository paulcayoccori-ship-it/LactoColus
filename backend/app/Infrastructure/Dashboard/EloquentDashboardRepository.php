<?php

declare(strict_types=1);

namespace App\Infrastructure\Dashboard;

use App\Domain\Dashboard\DashboardRepository;
use App\Infrastructure\Acopios\EntregaAcopio;
use App\Infrastructure\Acopios\JornadaAcopio;
use App\Infrastructure\Calidad\AnalisisCalidad;
use App\Infrastructure\Inventario\ExistenciaQueso;
use App\Infrastructure\Liquidaciones\Liquidacion;
use App\Infrastructure\Liquidaciones\PagoLiquidacion;
use App\Infrastructure\Produccion\AjusteProduccion;
use App\Infrastructure\Produccion\AlertaRendimiento;
use App\Infrastructure\Produccion\LoteProduccion;
use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Recepciones\AlertaConciliacion;
use App\Infrastructure\Recepciones\RecepcionPlanta;
use App\Infrastructure\Rutas\RutaAcopio;
use App\Infrastructure\Ventas\Venta;
use App\Models\User;

final class EloquentDashboardRepository implements DashboardRepository
{
    public function summary(): array
    {
        $counts = Productor::query()->selectRaw('COUNT(*) AS total, COALESCE(SUM(CASE WHEN estado = 1 THEN 1 ELSE 0 END), 0) AS activos')->first();

        $lots = LoteProduccion::where('estado', 'finalizado');
        $liters = bcadd((string) (clone $lots)->sum('litros_cuba'), '0', 3);
        $molds = (int) (clone $lots)->sum('moldes') + (int) AjusteProduccion::whereIn('lote_id', (clone $lots)->select('id'))->sum('delta_moldes');
        $production = ['litros_procesados' => $liters, 'moldes_producidos' => $molds, 'rendimiento_promedio' => bccomp($liters, '0', 3) > 0 ? bcdiv(bcmul((string) $molds, '100', 6), $liters, 6) : '0.000000', 'alertas_rendimiento' => AlertaRendimiento::where('estado', 'pendiente')->count(), 'stock_queso' => ExistenciaQueso::orderBy('tipo')->pluck('moldes', 'tipo')->all(), 'ventas_hoy' => Venta::whereIn('estado', ['confirmada', 'pagada'])->whereDate('vendida_at', today())->count(), 'importe_ventas_hoy' => bcadd((string) Venta::whereIn('estado', ['confirmada', 'pagada'])->whereDate('vendida_at', today())->sum('total'), '0', 2)];

        return $production + ['liquidaciones_pendientes' => Liquidacion::whereIn('estado', ['calculada', 'aprobada'])->count(), 'pagos_hoy' => PagoLiquidacion::whereDate('pagado_at', today())->count(), 'importe_pagado_hoy' => bcadd((string) PagoLiquidacion::whereDate('pagado_at', today())->sum('importe'), '0', 2), 'analisis_hoy' => AnalisisCalidad::where('estado', '!=', 'anulado')->whereDate('muestra_at', today())->count(), 'analisis_observados' => AnalisisCalidad::where('estado', 'observado')->count(), 'analisis_pendientes' => AnalisisCalidad::where('estado', 'pendiente_revision')->count(), 'rutas_activas' => RutaAcopio::query()->where('estado', true)->count(), 'jornadas_abiertas' => JornadaAcopio::query()->where('estado', 'abierta')->count(), 'litros_hoy' => (string) (EntregaAcopio::query()->whereDate('recolectada_at', today())->sum('litros') ?: '0.000'), 'recepciones_hoy' => RecepcionPlanta::query()->whereDate('recibida_at', today())->where('resultado', '!=', 'anulada')->count(), 'litros_recibidos_hoy' => number_format((float) RecepcionPlanta::query()->whereDate('recibida_at', today())->where('resultado', '!=', 'anulada')->sum('litros_planta'), 3, '.', ''), 'alertas_pendientes' => AlertaConciliacion::query()->where('estado', 'pendiente')->count(), 'total' => (int) $counts->total, 'activos' => (int) $counts->activos,
            'inactivos' => (int) $counts->total - (int) $counts->activos, 'usuarios' => User::query()->count(),
            'recientes' => Productor::query()->latest('created_at')->orderByDesc('id')->limit(5)->get(['id', 'codigo', 'nombres', 'apellidos', 'estado', 'created_at'])->toArray()];
    }
}
