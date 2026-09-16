<?php

namespace App\Application\Liquidaciones;

use App\Infrastructure\Liquidaciones\PeriodoLiquidacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProtegerFuentesLiquidacion
{
    public function date(mixed $date): void
    {
        $day = Carbon::parse($date)->setTimezone(config('app.timezone'))->toDateString();
        if (PeriodoLiquidacion::whereNotIn('estado', ['abierto', 'anulado'])->whereDate('desde', '<=', $day)->whereDate('hasta', '>=', $day)->exists()) {
            throw ValidationException::withMessages(['periodo' => 'El periodo está cerrado y sus fuentes están congeladas. Registra una corrección financiera mediante ajuste auditado.']);
        }
    }

    public function sale(int $id): void
    {
        if (DB::table('periodo_ventas')->where('venta_id', $id)->where('vigente', true)->exists()) {
            throw ValidationException::withMessages(['liquidacion' => 'Esta venta está vinculada a una liquidación cerrada. No puede pagarse o anularse por separado.']);
        }
    }
}
