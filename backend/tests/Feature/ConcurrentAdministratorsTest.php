<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentAdministratorsTest extends TestCase
{
    #[TestWith(['demote'])]
    #[TestWith(['deactivate'])]
    public function test_concurrent_requests_leave_an_active_administrator(string $operation): void
    {
        $database = tempnam(sys_get_temp_dir(), 'lactocolus-test-');
        $barrier = $database.'-start';
        $originalConnection = DB::getDefaultConnection();
        config(['database.connections.concurrent' => array_replace(config('database.connections.sqlite'), ['database' => $database, 'url' => null, 'busy_timeout' => 5000, 'journal_mode' => 'WAL'])]);
        DB::setDefaultConnection('concurrent');
        $processes = [];

        try {
            Artisan::call('migrate', ['--database' => 'concurrent', '--no-interaction' => true]);
            Role::findOrCreate('administrador', 'web');
            Role::findOrCreate('contador', 'web');
            $admins = User::factory()->count(2)->create();
            $admins->each(fn (User $user) => $user->assignRole('administrador'));
            $worker = <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[1], 'database.connections.sqlite.url' => null, 'database.connections.sqlite.busy_timeout' => 5000, 'database.connections.sqlite.journal_mode' => 'WAL', 'cache.default' => 'array', 'session.driver' => 'array']);
Illuminate\Support\Facades\DB::purge('sqlite');
$actor = App\Models\User::query()->findOrFail((int) $argv[2]);
Illuminate\Support\Facades\Auth::guard('web')->setUser($actor);
file_put_contents($argv[1]."-".$argv[2]."-ready", "ready");
$deadline = microtime(true) + 10;
while (! file_exists($argv[4])) {
    if (microtime(true) > $deadline) { exit(2); }
    usleep(10000);
}
try {
    $save = app(App\Application\Usuarios\GuardarUsuario::class);
    if ($argv[5] === 'demote') {
        $save->handle($actor->id, $actor->id, ['name' => $actor->name, 'email' => $actor->email, 'active' => true, 'roles' => ['contador']]);
    } else {
        $save->changeState($actor->id, (int) $argv[3], false);
    }
    echo "saved\n";
} catch (Illuminate\Validation\ValidationException|Illuminate\Auth\Access\AuthorizationException|Symfony\Component\HttpKernel\Exception\HttpException $exception) {
    echo "blocked\n";
}
CODE;
            foreach ([0, 1] as $index) {
                $process = new Process([PHP_BINARY, '-r', $worker, $database, (string) $admins[$index]->id, (string) $admins[1 - $index]->id, $barrier, $operation], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array'], timeout: 20);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 5;
            while ((! file_exists($database.'-'.$admins[0]->id.'-ready') || ! file_exists($database.'-'.$admins[1]->id.'-ready')) && microtime(true) < $deadline) {
                usleep(10000);
            }
            foreach ($admins as $admin) {
                $this->assertFileExists($database.'-'.$admin->id.'-ready');
            }
            touch($barrier);
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $results[] = trim($process->getOutput());
            }
            sort($results);
            $this->assertSame(['blocked', 'saved'], $results);
            $this->assertSame(1, User::query()->where('active', true)->role('administrador', 'web')->count());
            $this->assertSame(2, User::query()->count());
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            DB::purge('concurrent');
            DB::setDefaultConnection($originalConnection);
            foreach ([$database, $database.'-wal', $database.'-shm', $barrier, $database.'-1-ready', $database.'-2-ready'] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }
}
