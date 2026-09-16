<?php

namespace App\Application\Ranking;

use App\Infrastructure\Calidad\AnalisisCalidad;
use App\Infrastructure\Ranking\CalculoRanking;

final class InvalidarRanking
{
    public function analysis(AnalisisCalidad $record, ?string $previousDate = null): void
    {
        $dates = array_unique(array_filter([$record->muestra_at->toDateString(), $previousDate ? substr($previousDate, 0, 10) : null]));
        CalculoRanking::where('vigente', true)->where(function ($q) use ($dates): void {
            foreach ($dates as $date) {
                $q->orWhere(fn ($q) => $q->whereDate('desde', '<=', $date)->whereDate('hasta', '>=', $date));
            }
        })->update(['vigente' => false]);
    }
}
