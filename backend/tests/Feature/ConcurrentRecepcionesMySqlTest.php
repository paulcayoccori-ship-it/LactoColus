<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Acopios\GestionarAcopios;
use App\Application\Recepciones\GestionarRecepciones;
use App\Infrastructure\Acopios\EntregaAcopio;
use App\Infrastructure\Acopios\JornadaAcopio;
use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Rutas\RutaAcopio;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ConcurrentRecepcionesMySqlTest extends TestCase
{
    public function test_same_external_uuid_is_idempotent_under_mysql_concurrency(): void
    {
        if (getenv('LACTOCOLUS_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('Requiere un MySQL exclusivo; ejecutar con LACTOCOLUS_MYSQL_TESTS=1.');
        }

        $database = 'lactocolus_test_recepciones_'.bin2hex(random_bytes(8));
        $original = config('database.default');
        $serverConfig = array_replace(config('database.connections.mysql'), ['database' => null, 'url' => null]);
        config(['database.connections.recepciones_server' => $serverConfig]);
        $server = DB::connection('recepciones_server');
        $created = false;
        $processes = [];

        try {
            $server->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $created = true;
            config(['database.connections.recepciones_mysql' => array_replace($serverConfig, ['database' => $database])]);
            DB::setDefaultConnection('recepciones_mysql');
            Artisan::call('migrate', ['--database' => 'recepciones_mysql', '--no-interaction' => true]);
            Role::findOrCreate('administrador', 'web');
            $admin = User::factory()->create(['active' => true]);
            $admin->assignRole('administrador');
            $collector = User::factory()->create(['active' => true]);
            Role::findOrCreate('recolector', 'web');
            $collector->assignRole('recolector');
            $route = RutaAcopio::factory()->create(['recolector_id' => $collector->id]);
            $producer = Productor::factory()->create();
            DB::table('ruta_productor')->insert(['ruta_id' => $route->id, 'productor_id' => $producer->id, 'orden' => 1]);
            $journey = app(GestionarAcopios::class)->createJourney($collector->id, ['ruta_id' => $route->id, 'fecha_operativa' => '2026-09-09', 'turno' => 'primera_vuelta'], true);
            JornadaAcopio::query()->whereKey($journey['id'])->update(['estado' => 'cerrada']);
            EntregaAcopio::create(['uuid_cliente' => (string) Str::uuid(), 'jornada_id' => $journey['id'], 'ruta_id' => $route->id, 'productor_id' => $producer->id, 'recolector_id' => $collector->id, 'litros' => '10.000', 'recolectada_at' => '2026-09-09 08:00:00', 'sincronizada_at' => now()]);
            app(GestionarRecepciones::class)->saveTolerance($admin->id, '5');
            $uuid = (string) Str::uuid();
            $worker = <<<'PHP'
require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); config(['database.default'=>'mysql','database.connections.mysql.database'=>$argv[1],'database.connections.mysql.url'=>null]); Illuminate\Support\Facades\DB::purge('mysql'); try { $r=app(App\Application\Recepciones\GestionarRecepciones::class)->create((int)$argv[2], ['jornada_id'=>(int)$argv[3],'uuid_lectura_externa'=>$argv[4],'litros_planta'=>'10.000','recibida_at'=>'2026-09-09 12:00:00','fuente_medicion'=>'sensor'], true); echo json_encode(['estado'=>$r['estado_sincronizacion']]); } catch (Throwable $e) { echo 'error:'.$e->getMessage(); exit(1); }
PHP;
            foreach (range(1, 2) as $i) {
                $process = new Process([PHP_BINARY, '-r', $worker, $database, (string) $admin->id, (string) $journey['id'], $uuid], base_path(), ['APP_ENV' => 'testing'], timeout: 30);
                $process->start();
                $processes[] = $process;
            }
            $outputs = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $outputs[] = $process->getOutput();
            }
            $this->assertDatabaseCount('recepciones_planta', 1);
            $this->assertStringContainsString('creada', implode(' ', $outputs));
            $this->assertStringContainsString('repetida', implode(' ', $outputs));
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            DB::purge('recepciones_mysql');
            DB::setDefaultConnection($original);
            if ($created) {
                $server->statement('DROP DATABASE `'.$database.'`');
            }
            DB::purge('recepciones_server');
        }
    }
}
