<?php

namespace Tests\Feature;

use App\Application\Traslados\GestionarTraslados;
use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Rutas\RutaAcopio;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentTrasladosMySqlTest extends TestCase
{
    #[TestWith(['solicitar'])]
    #[TestWith(['aplicar'])]
    public function test_simultaneous_requests_and_applications_are_consistent(string $mode): void
    {
        if (getenv('LACTOCOLUS_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('Optativa: requiere crear una base MySQL exclusiva.');
        }
        $database = 'lactocolus_test_traslados_'.bin2hex(random_bytes(8));
        $original = DB::getDefaultConnection();
        $serverConfig = array_replace(config('database.connections.mysql'), ['database' => null, 'url' => null]);
        config(['database.connections.traslados_server' => $serverConfig]);
        $server = DB::connection('traslados_server');
        $created = false;
        $processes = [];
        try {
            $server->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $created = true;
            config(['database.connections.traslados_test' => array_replace($serverConfig, ['database' => $database])]);
            DB::setDefaultConnection('traslados_test');
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'traslados_test', '--no-interaction' => true]));
            Role::findOrCreate('administrador', 'web');
            $user = User::factory()->create(['active' => true]);
            $user->assignRole('administrador');
            $producer = Productor::factory()->create(['estado' => true]);
            $old = RutaAcopio::factory()->create(['estado' => true]);
            $target = RutaAcopio::factory()->create(['estado' => true]);
            DB::table('ruta_productor')->insert(['productor_id' => $producer->id, 'ruta_id' => $old->id, 'orden' => 1]);
            $service = app(GestionarTraslados::class);
            $service->configuration($user->id, ['anticipacion_dias' => 3, 'motivo' => 'Regla de prueba']);
            $payload = ['uuid' => (string) Str::uuid(), 'productor_id' => $producer->id, 'ruta_solicitada_id' => $target->id, 'fecha_efectiva' => today()->addDays(3)->toDateString(), 'motivo' => 'Solicitud de prueba'];
            $uuid = '';
            if ($mode === 'aplicar') {
                $record = $service->request($user->id, $payload);
                $service->decide($user->id, $record->uuid, true, 'Aprobación de prueba');
                DB::table('solicitudes_traslado')->where('id', $record->id)->update(['fecha_efectiva' => today()->toDateString()]);
                $uuid = $record->uuid;
            }
            $worker = <<<'PHP'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!preg_match('/^lactocolus_test_traslados_[a-f0-9]{16}$/', $argv[1])) { exit(2); }
config(['database.default'=>'mysql','database.connections.mysql.database'=>$argv[1],'database.connections.mysql.url'=>null,'cache.default'=>'array']);
Illuminate\Support\Facades\DB::purge('mysql');
echo "READY\n"; fflush(STDOUT); fgets(STDIN);
try {
    $service=app(App\Application\Traslados\GestionarTraslados::class);
    $result=$argv[3]==='solicitar'?$service->request((int)$argv[2],json_decode($argv[4],true)):$service->apply((int)$argv[2],$argv[5]);
    echo json_encode(['estado'=>'creado','uuid'=>$result->uuid]);
} catch (Illuminate\Validation\ValidationException $e) { echo json_encode(['estado'=>'rechazado']);
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()); exit(1); }
PHP;
            $streams = [];
            foreach (range(1, 2) as $i) {
                $stream = new InputStream;
                $streams[] = $stream;
                $payload['uuid'] = (string) Str::uuid();
                $process = new Process([PHP_BINARY, '-r', $worker, $database, (string) $user->id, $mode, json_encode($payload), $uuid], base_path(), ['APP_ENV' => 'testing'], timeout: 20);
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
            $this->assertSame(1, DB::table('solicitudes_traslado')->count());
            $this->assertSame(1, DB::table('ruta_productor')->where('productor_id', $producer->id)->count());
            if ($mode === 'solicitar') {
                $states = array_column($outputs, 'estado');
                sort($states);
                $this->assertSame(['creado', 'rechazado'], $states);
                $this->assertSame($old->id, DB::table('ruta_productor')->where('productor_id', $producer->id)->value('ruta_id'));
            } else {
                $this->assertSame([$uuid, $uuid], array_column($outputs, 'uuid'));
                $this->assertSame(1, DB::table('historial_traslados')->count());
                $this->assertSame($target->id, DB::table('ruta_productor')->where('productor_id', $producer->id)->value('ruta_id'));
            }

        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            DB::purge('traslados_test');
            DB::setDefaultConnection($original);
            if ($created) {
                $server->statement('DROP DATABASE `'.$database.'`');
            }
            DB::purge('traslados_server');
        }
    }
}
