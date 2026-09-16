<?php

namespace Tests\Feature;

use App\Application\Penalizaciones\GestionarPenalizaciones;
use App\Infrastructure\Calidad\AnalisisCalidad;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentPenalizacionesMySqlTest extends TestCase
{
    public function test_simultaneous_evaluations_keep_one_sanction(): void
    {
        if (getenv('LACTOCOLUS_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('Optativa: requiere crear una base MySQL exclusiva.');
        }
        $database = 'lactocolus_test_penalizaciones_'.bin2hex(random_bytes(8));
        $original = DB::getDefaultConnection();
        $serverConfig = array_replace(config('database.connections.mysql'), ['database' => null, 'url' => null]);
        config(['database.connections.penalizaciones_server' => $serverConfig]);
        $server = DB::connection('penalizaciones_server');
        $created = false;
        $processes = [];
        try {
            $server->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $created = true;
            config(['database.connections.penalizaciones_test' => array_replace($serverConfig, ['database' => $database])]);
            DB::setDefaultConnection('penalizaciones_test');
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'penalizaciones_test', '--no-interaction' => true]));
            Role::findOrCreate('administrador', 'web');
            $user = User::factory()->create(['active' => true]);
            $user->assignRole('administrador');
            app(GestionarPenalizaciones::class)->configure($user->id, ['activo' => true, 'tarifa_primera' => '1.20', 'tarifa_grave' => '0.65', 'alcance_tarifa' => 'dia', 'unidad_falta' => 'analisis', 'ventana_dias' => null, 'motivo' => 'Reglas sintéticas de prueba']);
            $analysis = AnalisisCalidad::factory()->create(['agua_anadida' => '1']);
            $worker = <<<'PHP'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!preg_match('/^lactocolus_test_penalizaciones_[a-f0-9]{16}$/', $argv[1])) { exit(2); }
config(['database.default'=>'mysql','database.connections.mysql.database'=>$argv[1],'database.connections.mysql.url'=>null,'cache.default'=>'array']);
Illuminate\Support\Facades\DB::purge('mysql');
echo "READY\n"; fflush(STDOUT); fgets(STDIN);
try {
    app(App\Application\Penalizaciones\GestionarPenalizaciones::class)->recalculate((int)$argv[2],(int)$argv[3],'Revisión concurrente de prueba');
    $record=App\Infrastructure\Penalizaciones\SancionCalidad::sole();
    echo json_encode(['uuid'=>$record->uuid]);
} catch (Illuminate\Validation\ValidationException $e) { echo json_encode(['estado'=>'rechazado']);
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()); exit(1); }
PHP;
            $streams = [];
            foreach (range(1, 2) as $i) {
                $stream = new InputStream;
                $streams[] = $stream;
                $process = new Process([PHP_BINARY, '-r', $worker, $database, (string) $user->id, (string) $analysis->productor_id], base_path(), ['APP_ENV' => 'testing'], timeout: 20);
                $process->setInput($stream);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            do {
                $ready = array_map(fn (Process $process): bool => str_contains($process->getOutput(), 'READY'), $processes);
                if (! in_array(false, $ready, true)) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame([true, true], $ready, 'Los dos procesos deben estar listos antes de liberar la barrera.');
            foreach ($streams as $stream) {
                $stream->write("GO\n");
                $stream->close();
            }
            foreach ($processes as $process) {
                $process->getOutput();
            }
            $outputs = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $outputs[] = json_decode(trim(str_replace('READY', '', $process->getOutput())), true, 512, JSON_THROW_ON_ERROR);
            }
            $this->assertSame(1, DB::table('sanciones_calidad')->count());
            $this->assertSame('pendiente', DB::table('sanciones_calidad')->value('estado'));
            $this->assertSame($outputs[0]['uuid'], $outputs[1]['uuid']);

        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            DB::purge('penalizaciones_test');
            DB::setDefaultConnection($original);
            if ($created) {
                $server->statement('DROP DATABASE `'.$database.'`');
            }
            DB::purge('penalizaciones_server');
        }
    }
}
