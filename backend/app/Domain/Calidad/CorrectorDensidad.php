<?php

namespace App\Domain\Calidad;

interface CorrectorDensidad
{
    /** Devuelve null mientras no exista una fórmula aprobada. */
    public function corregir(string $densidad, string $temperatura): ?string;
}
