<?php

namespace Tests\Feature;

use App\Infrastructure\Produccion\LoteProduccion;
use App\Infrastructure\Produccion\UsoRecepcion;
use App\Infrastructure\Recepciones\RecepcionPlanta;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentProduccionMySqlTest extends TestCase
{
    public function test_simultaneous_reservations_never_overconsume_milk(): void
    {
        if (getenv('LACTOCOLUS_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('Optativa: requiere crear una base MySQL exclusiva.');
        }
        $database = 'lactocolus_test_produccion_'.bin2hex(random_bytes(8));
        $original = DB::getDefaultConnection();
        $serverConfig = array_replace(config('database.connections.mysql'), ['database' => null, 'url' => null]);
        config(['database.connections.produccion_server' => $serverConfig]);
        $server = DB::connection('produccion_server');
        $created = false;
        $processes = [];
        try {
            $server->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $created = true;
            config(['database.connections.produccion_test' => array_replace($serverConfig, ['database' => $database])]);
            DB::setDefaultConnection('produccion_test');
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'produccion_test', '--no-interaction' => true]));
            Role::findOrCreate('administrador', 'web');
            $user = User::factory()->create(['active' => true]);
            $user->assignRole('administrador');
            $producer = RecepcionPlanta::factory()->create(['litros_planta' => '100.000']);
            $uuid = (string) Str::uuid();
            $worker = <<<'PHP'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!preg_match('/^lactocolus_test_produccion_[a-f0-9]{16}$/', $argv[1])) { exit(2); }
config(['database.default'=>'mysql','database.connections.mysql.database'=>$argv[1],'database.connections.mysql.url'=>null,'cache.default'=>'array']);
Illuminate\Support\Facades\DB::purge('mysql');
echo "READY\n"; fflush(STDOUT); fgets(STDIN);
try {
    $result=app(App\Application\Produccion\GestionarProduccion::class)->create((int)$argv[2], ['uuid'=>$argv[4],'codigo'=>'LOTE-'.$argv[4],'tipo'=>'paria_fresco','producido_at'=>now()->toDateTimeString(),'recepciones'=>[['recepcion_id'=>(int)$argv[3],'litros'=>'80.000']]]);
    echo json_encode(['estado'=>'creado','uuid'=>$result->uuid]);
} catch (Illuminate\Validation\ValidationException $e) { echo json_encode(['estado'=>'rechazado']);
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()); exit(1); }
PHP;
            $streams = [];
            foreach (range(1, 2) as $i) {
                $stream = new InputStream;
                $streams[] = $stream;
                $process = new Process([PHP_BINARY, '-r', $worker, $database, (string) $user->id, (string) $producer->id, (string) Str::uuid()], base_path(), ['APP_ENV' => 'testing'], timeout: 20);
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
            $this->assertSame(1, LoteProduccion::count());
            $states = array_column($outputs, 'estado');
            sort($states);
            $this->assertSame(['creado', 'rechazado'], $states);
            $this->assertSame('80.000', UsoRecepcion::occupied($producer->id));
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            DB::purge('produccion_test');
            DB::setDefaultConnection($original);
            if ($created) {
                $server->statement('DROP DATABASE `'.$database.'`');
            }
            DB::purge('produccion_server');
        }
    }
}
