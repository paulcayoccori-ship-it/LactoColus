<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Rutas\RutaAcopio;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentRutasMySqlTest extends TestCase
{
    #[TestWith(['same_producer_other_route'])]
    #[TestWith(['same_producer_same_route'])]
    #[TestWith(['different_producers_same_route'])]
    public function test_overlapping_mysql_assignments_preserve_uniqueness_and_order(string $scenario): void
    {
        if (getenv('LACTOCOLUS_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('MySQL aislado: ejecutar con LACTOCOLUS_MYSQL_TESTS=1; crea una base exclusiva nueva.');
        }
        $database = 'lactocolus_test_rutas_'.bin2hex(random_bytes(8));
        $this->assertMatchesRegularExpression('/\Alactocolus_test_rutas_[a-f0-9]{16}\z/', $database);
        $original = DB::getDefaultConnection();
        $mysql = array_replace(config('database.connections.mysql'), ['database' => null, 'url' => null]);
        config(['database.connections.rutas_test_server' => $mysql]);
        $server = DB::connection('rutas_test_server');
        $created = false;
        $processes = [];
        $barrier = sys_get_temp_dir().'/'.$database;
        try {
            $server->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $created = true;
            config(['database.connections.rutas_mysql_test' => array_replace($mysql, ['database' => $database])]);
            DB::setDefaultConnection('rutas_mysql_test');
            $this->assertSame($database, DB::selectOne('SELECT DATABASE() AS db')->db);
            Artisan::call('migrate', ['--database' => 'rutas_mysql_test', '--no-interaction' => true]);
            $this->assertSame('InnoDB', DB::selectOne("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'ruta_productor'", [$database])->ENGINE);
            Role::findOrCreate('administrador', 'web');
            $admin = User::factory()->create();
            $admin->assignRole('administrador');
            $routes = RutaAcopio::factory()->count(2)->create();
            $producers = Productor::factory()->count(2)->create();
            $worker = <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! preg_match('/\Alactocolus_test_rutas_[a-f0-9]{16}\z/', $argv[1])) { exit(10); }
config(['database.default' => 'mysql', 'database.connections.mysql.database' => $argv[1], 'database.connections.mysql.url' => null, 'cache.default' => 'array', 'session.driver' => 'array']);
Illuminate\Support\Facades\DB::purge('mysql');
if (Illuminate\Support\Facades\DB::selectOne('SELECT DATABASE() AS db')->db !== $argv[1]) { exit(11); }
Illuminate\Support\Facades\Auth::guard('web')->setUser(App\Models\User::query()->findOrFail((int) $argv[2]));
$assign = fn () => app(App\Application\Rutas\GestionarRutas::class)->attach((int) $argv[3], (int) $argv[4]);
try {
    if ($argv[6] === 'first') {
        app(App\Domain\Usuarios\UsuarioRepository::class)->underAdminLock(function () use ($argv, $assign) {
            file_put_contents($argv[5].'-locked', 'locked');
            $deadline = microtime(true) + 15;
            while (! file_exists($argv[5].'-release')) {
                if (microtime(true) > $deadline) { throw new RuntimeException('Barrier timeout'); }
                usleep(10000);
            }
            $assign();
        });
    } else {
        file_put_contents($argv[5].'-attempting', 'attempting');
        $assign();
    }
    echo 'saved';
} catch (Illuminate\Validation\ValidationException $exception) {
    echo 'conflict';
}
CODE;
            $first = new Process([PHP_BINARY, '-r', $worker, $database, (string) $admin->id, (string) $routes[0]->id, (string) $producers[0]->id, $barrier, 'first'], base_path(), ['APP_ENV' => 'testing'], timeout: 25);
            $first->start();
            $processes[] = $first;
            $this->waitForFile($barrier.'-locked', $first);
            $second = new Process([PHP_BINARY, '-r', $worker, $database, (string) $admin->id, (string) $routes[$scenario === 'same_producer_other_route' ? 1 : 0]->id, (string) $producers[$scenario === 'different_producers_same_route' ? 1 : 0]->id, $barrier, 'second'], base_path(), ['APP_ENV' => 'testing'], timeout: 25);
            $second->start();
            $processes[] = $second;
            $this->waitForFile($barrier.'-attempting', $second);
            usleep(200000);
            $this->assertTrue($second->isRunning(), 'La segunda solicitud debe esperar al bloqueo MySQL. '.$second->getErrorOutput());
            $this->assertSame('', $second->getOutput());
            touch($barrier.'-release');
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $results[] = trim($process->getOutput());
            }
            sort($results);
            if ($scenario === 'different_producers_same_route') {
                $this->assertSame(['saved', 'saved'], $results);
                $this->assertSame([1, 2], DB::table('ruta_productor')->orderBy('orden')->pluck('orden')->all());
            } else {
                $this->assertSame(['conflict', 'saved'], $results);
                $this->assertDatabaseCount('ruta_productor', 1);
                $this->assertDatabaseHas('ruta_productor', ['ruta_id' => $routes[0]->id, 'productor_id' => $producers[0]->id, 'orden' => 1]);
            }
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            DB::purge('rutas_mysql_test');
            DB::setDefaultConnection($original);
            if ($created) {
                $server->statement('DROP DATABASE `'.$database.'`');
            }
            DB::purge('rutas_test_server');
            foreach (['-locked', '-attempting', '-release'] as $suffix) {
                if (file_exists($barrier.$suffix)) {
                    unlink($barrier.$suffix);
                }
            }
        }
    }

    private function waitForFile(string $path, Process $process): void
    {
        $deadline = microtime(true) + 10;
        while (! file_exists($path) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($path, $process->getErrorOutput());
    }
}
