<?php

namespace Tests\Feature;

use App\Application\Inventario\GestionarInventario;
use App\Application\Ventas\GestionarVentas;
use App\Infrastructure\Inventario\ExistenciaQueso;
use App\Infrastructure\Ventas\Cliente;
use App\Infrastructure\Ventas\Venta;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentVentasMySqlTest extends TestCase
{
    public function test_simultaneous_sales_cannot_sell_the_same_stock(): void
    {
        if (getenv('LACTOCOLUS_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('Optativa: requiere crear una base MySQL exclusiva.');
        }
        $database = 'lactocolus_test_ventas_'.bin2hex(random_bytes(8));
        $original = DB::getDefaultConnection();
        $serverConfig = array_replace(config('database.connections.mysql'), ['database' => null, 'url' => null]);
        config(['database.connections.ventas_server' => $serverConfig]);
        $server = DB::connection('ventas_server');
        $created = false;
        $processes = [];
        try {
            $server->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $created = true;
            config(['database.connections.ventas_test' => array_replace($serverConfig, ['database' => $database])]);
            DB::setDefaultConnection('ventas_test');
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'ventas_test', '--no-interaction' => true]));
            Role::findOrCreate('administrador', 'web');
            $user = User::factory()->create(['active' => true]);
            $user->assignRole('administrador');
            $client = Cliente::factory()->create();
            app(GestionarInventario::class)->adjust($user->id, ['uuid' => (string) Str::uuid(), 'tipo' => 'paria_fresco', 'delta' => 1, 'motivo' => 'Stock de prueba concurrente']);
            $sales = [];
            foreach (range(1, 2) as $i) {
                $sales[] = app(GestionarVentas::class)->create($user->id, ['uuid' => (string) Str::uuid(), 'cliente_id' => $client->id, 'vendida_at' => now()->toDateTimeString(), 'descuento' => '0', 'descontar_liquidacion' => false, 'detalles' => [['tipo' => 'paria_fresco', 'moldes' => 1]]])->uuid;
            }

            $worker = <<<'PHP'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!preg_match('/^lactocolus_test_ventas_[a-f0-9]{16}$/', $argv[1])) { exit(2); }
config(['database.default'=>'mysql','database.connections.mysql.database'=>$argv[1],'database.connections.mysql.url'=>null,'cache.default'=>'array']);
Illuminate\Support\Facades\DB::purge('mysql');
echo "READY\n"; fflush(STDOUT); fgets(STDIN);
try {
    $result=app(App\Application\Ventas\GestionarVentas::class)->confirm((int)$argv[2],$argv[3]);
    echo json_encode(['estado'=>'creado','uuid'=>$result->uuid]);
} catch (Illuminate\Validation\ValidationException $e) { echo json_encode(['estado'=>'rechazado']);
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()); exit(1); }
PHP;
            $streams = [];
            foreach (range(1, 2) as $i) {
                $stream = new InputStream;
                $streams[] = $stream;
                $process = new Process([PHP_BINARY, '-r', $worker, $database, (string) $user->id, $sales[$i - 1]], base_path(), ['APP_ENV' => 'testing'], timeout: 20);
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
            $this->assertSame(1, Venta::where('estado', 'confirmada')->count());
            $states = array_column($outputs, 'estado');
            sort($states);
            $this->assertSame(['creado', 'rechazado'], $states);
            $this->assertSame(0, ExistenciaQueso::find('paria_fresco')->moldes);
            $this->assertSame(1, Venta::where('estado', 'borrador')->count());
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            DB::purge('ventas_test');
            DB::setDefaultConnection($original);
            if ($created) {
                $server->statement('DROP DATABASE `'.$database.'`');
            }
            DB::purge('ventas_server');
        }
    }
}
