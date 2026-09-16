<?php

namespace Tests\Feature;

use App\Application\Liquidaciones\CalcularLiquidaciones;
use App\Application\Liquidaciones\GestionarLiquidaciones;
use App\Application\Liquidaciones\GestionarPeriodos;
use App\Infrastructure\Acopios\EntregaAcopio;
use App\Infrastructure\Acopios\JornadaAcopio;
use App\Infrastructure\Liquidaciones\Liquidacion;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentLiquidacionesMySqlTest extends TestCase
{
    #[TestWith(['pago'])]
    #[TestWith(['periodo'])]
    public function test_simultaneous_requests_preserve_financial_uniqueness(string $operation): void
    {
        if (getenv('LACTOCOLUS_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('Optativa: requiere crear una base MySQL exclusiva.');
        }
        $database = 'lactocolus_test_liquidaciones_'.bin2hex(random_bytes(8));
        $original = DB::getDefaultConnection();
        $serverConfig = array_replace(config('database.connections.mysql'), ['database' => null, 'url' => null]);
        config(['database.connections.liquidaciones_server' => $serverConfig]);
        $server = DB::connection('liquidaciones_server');
        $created = false;
        $processes = [];
        try {
            $server->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $created = true;
            config(['database.connections.liquidaciones_test' => array_replace($serverConfig, ['database' => $database])]);
            DB::setDefaultConnection('liquidaciones_test');
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'liquidaciones_test', '--no-interaction' => true]));
            Role::findOrCreate('administrador', 'web');
            $user = User::factory()->create(['active' => true]);
            $user->assignRole('administrador');
            $period = app(GestionarPeriodos::class)->create($user->id, ['uuid' => (string) Str::uuid(), 'desde' => '2026-09-03', 'motivo' => 'Periodo de prueba']);
            $journey = JornadaAcopio::factory()->create(['estado' => 'cerrada', 'fecha_operativa' => '2026-09-03']);
            EntregaAcopio::factory()->create(['jornada_id' => $journey->id, 'litros' => '10.000', 'recolectada_at' => '2026-09-03 10:00:00']);
            app(GestionarPeriodos::class)->close($user->id, $period->uuid, 'Cierre');
            app(CalcularLiquidaciones::class)->calculate($user->id, $period->uuid, 'Cálculo');
            app(GestionarLiquidaciones::class)->approve($user->id, $period->uuid, 'Aprobación');
            $liquidation = Liquidacion::sole();
            $paymentUuid = (string) Str::uuid();
            $worker = <<<'PHP'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!preg_match('/^lactocolus_test_liquidaciones_[a-f0-9]{16}$/', $argv[1])) { exit(2); }
config(['database.default'=>'mysql','database.connections.mysql.database'=>$argv[1],'database.connections.mysql.url'=>null,'cache.default'=>'array']);
Illuminate\Support\Facades\DB::purge('mysql');
echo "READY\n"; fflush(STDOUT); fgets(STDIN);
try {
    if ($argv[5] === 'periodo') { $record=app(App\Application\Liquidaciones\GestionarPeriodos::class)->create((int)$argv[2],['uuid'=>(string)Illuminate\Support\Str::uuid(),'desde'=>'2026-09-10','motivo'=>'Apertura concurrente']); echo json_encode(['uuid'=>$record->uuid]); exit(0); }
    $record=app(App\Application\Liquidaciones\GestionarLiquidaciones::class)->pay((int)$argv[2],$argv[3],['uuid_externo'=>$argv[4],'metodo'=>'Prueba concurrente','pagado_at'=>now()->toDateTimeString()]);
    echo json_encode(['uuid'=>$record->uuid_externo]);
} catch (Illuminate\Validation\ValidationException $e) { echo json_encode(['estado'=>'rechazado']);
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()); exit(1); }
PHP;
            $streams = [];
            foreach (range(1, 2) as $i) {
                $stream = new InputStream;
                $streams[] = $stream;
                $process = new Process([PHP_BINARY, '-r', $worker, $database, (string) $user->id, (string) $liquidation->uuid, $paymentUuid, $operation], base_path(), ['APP_ENV' => 'testing'], timeout: 20);
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
            if ($operation === 'pago') {
                $this->assertSame(1, DB::table('pagos_liquidacion')->count());
                $this->assertSame('pagada', DB::table('liquidaciones')->value('estado'));
                $this->assertSame('17.00', DB::table('pagos_liquidacion')->value('importe'));
                $this->assertSame($outputs[0]['uuid'], $outputs[1]['uuid']);
            } else {
                $this->assertSame(1, DB::table('periodos_liquidacion')->where('desde', '2026-09-10')->count());
                $this->assertCount(1, array_filter($outputs, fn ($out) => isset($out['uuid'])));
                $this->assertCount(1, array_filter($outputs, fn ($out) => ($out['estado'] ?? null) === 'rechazado'));
            }

        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            DB::purge('liquidaciones_test');
            DB::setDefaultConnection($original);
            if ($created) {
                $server->statement('DROP DATABASE `'.$database.'`');
            }
            DB::purge('liquidaciones_server');
        }
    }
}
