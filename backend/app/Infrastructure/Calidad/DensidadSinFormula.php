<?php

namespace App\Infrastructure\Calidad;

use App\Domain\Calidad\CorrectorDensidad;

final class DensidadSinFormula implements CorrectorDensidad
{
    public function corregir(string $densidad, string $temperatura): ?string
    {
        return null;
    }
}
