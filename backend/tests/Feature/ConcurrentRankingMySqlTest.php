<?php

namespace Tests\Feature;

use App\Application\Ranking\GestionarRanking;
use App\Infrastructure\Calidad\AnalisisCalidad;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentRankingMySqlTest extends TestCase
{
    public function test_simultaneous_calculations_keep_one_snapshot(): void
    {
        if (getenv('LACTOCOLUS_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('Optativa: requiere crear una base MySQL exclusiva.');
        }
        $database = 'lactocolus_test_ranking_'.bin2hex(random_bytes(8));
        $original = DB::getDefaultConnection();
        $serverConfig = array_replace(config('database.connections.mysql'), ['database' => null, 'url' => null]);
        config(['database.connections.ranking_server' => $serverConfig]);
        $server = DB::connection('ranking_server');
        $created = false;
        $processes = [];
        try {
            $server->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $created = true;
            config(['database.connections.ranking_test' => array_replace($serverConfig, ['database' => $database])]);
            DB::setDefaultConnection('ranking_test');
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'ranking_test', '--no-interaction' => true]));
            Role::findOrCreate('administrador', 'web');
            $user = User::factory()->create(['active' => true]);
            $user->assignRole('administrador');
            app(GestionarRanking::class)->configure($user->id, ['activo' => true, 'privacidad' => 'codigo', 'agregacion' => 'media', 'inicio_semana' => 'jueves', 'motivo' => 'Configuración de prueba', 'bandas' => ['solidos_totales' => [['minimo' => '0', 'maximo' => '100', 'puntos' => '80']], 'densidad_corregida' => [['minimo' => '0', 'maximo' => '2', 'puntos' => '80']], 'acidez' => [['minimo' => '0', 'maximo' => '100', 'puntos' => '80']]]]);
            AnalisisCalidad::factory()->create(['solidos_totales' => '12', 'densidad_corregida' => '1.03']);
            $worker = <<<'PHP'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!preg_match('/^lactocolus_test_ranking_[a-f0-9]{16}$/', $argv[1])) { exit(2); }
config(['database.default'=>'mysql','database.connections.mysql.database'=>$argv[1],'database.connections.mysql.url'=>null,'cache.default'=>'array']);
Illuminate\Support\Facades\DB::purge('mysql');
echo "READY\n"; fflush(STDOUT); fgets(STDIN);
try {
    $result=app(App\Application\Ranking\GestionarRanking::class)->calculate((int)$argv[2],['tipo'=>'diario','fecha'=>today()->toDateString(),'motivo'=>'Cálculo concurrente de prueba']);
    echo json_encode(['estado'=>'creado','uuid'=>$result->uuid]);
} catch (Illuminate\Validation\ValidationException $e) { echo json_encode(['estado'=>'rechazado']);
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()); exit(1); }
PHP;
            $streams = [];
            foreach (range(1, 2) as $i) {
                $stream = new InputStream;
                $streams[] = $stream;
                $process = new Process([PHP_BINARY, '-r', $worker, $database, (string) $user->id], base_path(), ['APP_ENV' => 'testing'], timeout: 20);
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
            $this->assertSame(1, DB::table('calculos_ranking')->count());
            $this->assertSame(1, DB::table('resultados_ranking')->count());
            $this->assertSame($outputs[0]['uuid'], $outputs[1]['uuid']);

        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            DB::purge('ranking_test');
            DB::setDefaultConnection($original);
            if ($created) {
                $server->statement('DROP DATABASE `'.$database.'`');
            }
            DB::purge('ranking_server');
        }
    }
}
