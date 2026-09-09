<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Acopios\GestionarAcopios;
use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Rutas\RutaAcopio;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ConcurrentAcopiosMySqlTest extends TestCase
{
    public function test_same_client_uuid_is_idempotent_under_mysql_concurrency(): void
    {
        if (getenv('LACTOCOLUS_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('Requiere un MySQL exclusivo; ejecutar con LACTOCOLUS_MYSQL_TESTS=1.');
        }
        $database = 'lactocolus_test_acopios_'.bin2hex(random_bytes(8));
        $serverConfig = array_replace(config('database.connections.mysql'), ['database' => null, 'url' => null]);
        config(['database.connections.acopio_server' => $serverConfig]);
        $server = DB::connection('acopio_server');
        $created = false;
        $processes = [];
        try {
            $server->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $created = true;
            config(['database.connections.acopio_mysql' => array_replace($serverConfig, ['database' => $database])]);
            DB::setDefaultConnection('acopio_mysql');
            Artisan::call('migrate', ['--database' => 'acopio_mysql', '--no-interaction' => true]);
            Role::findOrCreate('recolector', 'web');
            $collector = User::factory()->create();
            $collector->assignRole('recolector');
            $route = RutaAcopio::factory()->create(['recolector_id' => $collector->id]);
            $producer = Productor::factory()->create();
            DB::table('ruta_productor')->insert(['ruta_id' => $route->id, 'productor_id' => $producer->id, 'orden' => 1]);
            $journey = app(GestionarAcopios::class)->createJourney($collector->id, ['ruta_id' => $route->id, 'fecha_operativa' => '2026-09-09', 'turno' => 'primera_vuelta'], true);
            $uuid = (string) Str::uuid();
            $worker = <<<'PHP'
require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); config(['database.default'=>'mysql','database.connections.mysql.database'=>$argv[1],'database.connections.mysql.url'=>null]); Illuminate\Support\Facades\DB::purge('mysql'); Illuminate\Support\Facades\Auth::guard('web')->setUser(App\Models\User::query()->findOrFail((int)$argv[2])); try { $r=app(App\Application\Acopios\GestionarAcopios::class)->sync((int)$argv[2], [['uuid_cliente'=>$argv[5],'jornada_id'=>(int)$argv[3],'productor_id'=>(int)$argv[4],'litros'=>'2.000','recolectada_at'=>'2026-09-09 08:00:00']]); echo json_encode($r); } catch (Throwable $e) { echo 'error:'.$e->getMessage(); exit(1); }
PHP;
            foreach (range(1, 2) as $i) {
                $process = new Process([PHP_BINARY, '-r', $worker, $database, (string) $collector->id, (string) $journey['id'], (string) $producer->id, $uuid], base_path(), ['APP_ENV' => 'testing'], timeout: 20);
                $process->start();
                $processes[] = $process;
            }
            $outputs = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $outputs[] = $process->getOutput();
            }
            $this->assertDatabaseCount('entregas_acopio', 1);
            $this->assertStringContainsString('creados', implode(' ', $outputs));
            $this->assertStringContainsString('repetidos', implode(' ', $outputs));
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            DB::purge('acopio_mysql');
            DB::setDefaultConnection(config('database.default'));
            if ($created) {
                $server->statement('DROP DATABASE `'.$database.'`');
            }
            DB::purge('acopio_server');
        }
    }
}
