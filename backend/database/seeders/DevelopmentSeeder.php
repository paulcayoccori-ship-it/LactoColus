<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Infrastructure\Productores\Productor;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['administrador', 'supervisor', 'recolector', 'contador'] as $name) {
            Role::findOrCreate($name, 'web');
        }
        $admin = User::query()->firstOrCreate(['email' => 'admin@lactocolus.test'], ['name' => 'Administrador local', 'password' => 'password']);
        $admin->assignRole('administrador');
        for ($number = 1; $number <= 20; $number++) {
            $codigo = sprintf('DEMO-%03d', $number);
            if (! Productor::withTrashed()->where('codigo', $codigo)->exists()) {
                Productor::factory()->create(['codigo' => $codigo, 'estado' => $number % 4 !== 0]);
            }
        }
    }
}
