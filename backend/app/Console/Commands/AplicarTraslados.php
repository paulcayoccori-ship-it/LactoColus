<?php

namespace App\Console\Commands;

use App\Application\Traslados\GestionarTraslados;
use Illuminate\Console\Command;

class AplicarTraslados extends Command
{
    protected $signature = 'traslados:aplicar';

    protected $description = 'Aplica traslados aprobados cuya fecha efectiva llegó';

    public function handle(GestionarTraslados $service): int
    {
        $result = $service->processDue();
        $this->info('Aplicados: '.$result['aplicadas'].'. Conflictos pendientes de revisión: '.$result['conflictos'].'.');

        return self::SUCCESS;
    }
}
