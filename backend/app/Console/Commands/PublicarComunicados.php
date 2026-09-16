<?php

namespace App\Console\Commands;

use App\Application\Comunicados\GestionarComunicados;
use Illuminate\Console\Command;

class PublicarComunicados extends Command
{
    protected $signature = 'comunicados:actualizar';

    protected $description = 'Publica y vence comunicados previamente autorizados';

    public function handle(GestionarComunicados $service): int
    {
        $this->info('Comunicados actualizados: '.$service->updateScheduled());

        return self::SUCCESS;
    }
}
