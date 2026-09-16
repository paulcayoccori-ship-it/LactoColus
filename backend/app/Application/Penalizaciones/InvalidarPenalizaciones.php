<?php

namespace App\Application\Penalizaciones;

use App\Application\Operacion\AuditarOperacion;
use App\Infrastructure\Calidad\AnalisisCalidad;
use App\Infrastructure\Penalizaciones\AsistenciaTecnica;
use App\Infrastructure\Penalizaciones\SancionCalidad;

final class InvalidarPenalizaciones
{
    public function __construct(private AuditarOperacion $audit) {}

    public function analysis(AnalisisCalidad $analysis, int $actor): void
    {
        $sanctions = SancionCalidad::where('productor_id', $analysis->productor_id)->where('requiere_revision', false)->pluck('id')->all();
        $assistance = AsistenciaTecnica::where('analisis_id', $analysis->id)->where('requiere_revision', false)->pluck('id')->all();
        SancionCalidad::whereIn('id', $sanctions)->update(['requiere_revision' => true]);
        AsistenciaTecnica::whereIn('id', $assistance)->update(['requiere_revision' => true]);
        if ($sanctions || $assistance) {
            $this->audit->record('penalizaciones', 'productor:'.$analysis->productor_id, 'revision_requerida', $actor, [], ['sanciones' => $sanctions, 'asistencias' => $assistance, 'analisis' => $analysis->uuid_publico], 'Cambio de análisis: se exige recálculo controlado, sin modificar importes históricos.');
        }
    }
}
