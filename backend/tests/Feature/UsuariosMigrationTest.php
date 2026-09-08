<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UsuariosMigrationTest extends TestCase
{
    public function test_upgrade_keeps_existing_accounts_active_and_preserves_credentials(): void
    {
        $originalConnection = DB::getDefaultConnection();
        config(['database.connections.upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('upgrade');

        try {
            $initial = require database_path('migrations/0001_01_01_000000_create_users_table.php');
            $initial->up();
            $user = User::factory()->create(['remember_token' => 'existing-remember-token']);
            $password = $user->password;
            $migration = require database_path('migrations/2026_09_08_191202_add_active_and_auth_version_to_users_table.php');

            $migration->up();

            $user->refresh();
            $this->assertTrue($user->active);
            $this->assertSame(0, $user->auth_version);
            $this->assertSame($password, $user->password);
            $this->assertSame('existing-remember-token', $user->remember_token);
        } finally {
            DB::purge('upgrade');
            DB::setDefaultConnection($originalConnection);
        }
    }
}
