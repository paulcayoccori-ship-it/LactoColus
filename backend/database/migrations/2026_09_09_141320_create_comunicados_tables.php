<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_productor', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('usuario_id')->unique()->constrained('users')->restrictOnDelete();
            $t->foreignId('productor_id')->unique()->constrained('productores')->restrictOnDelete();
            $t->boolean('activa')->default(true);
            $t->timestamps();
        });
        Schema::create('comunicados', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('titulo', 200);
            $t->text('contenido');
            $t->string('tipo', 25);
            $t->string('audiencia', 30);
            $t->foreignId('ruta_id')->nullable()->constrained('rutas_acopio')->restrictOnDelete();
            $t->json('roles_destino');
            $t->dateTime('publicar_at');
            $t->dateTime('vence_at')->nullable();
            $t->string('estado', 20)->default('borrador');
            $t->foreignId('autor_id')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['estado', 'publicar_at']);
        });
        Schema::create('comunicado_productor', function (Blueprint $t): void {
            $t->foreignId('comunicado_id')->constrained('comunicados')->restrictOnDelete();
            $t->foreignId('productor_id')->constrained('productores')->restrictOnDelete();
            $t->primary(['comunicado_id', 'productor_id']);
        });
        Schema::create('lecturas_comunicado', function (Blueprint $t): void {
            $t->foreignId('comunicado_id')->constrained('comunicados')->restrictOnDelete();
            $t->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $t->dateTime('leida_at');
            $t->primary(['comunicado_id', 'usuario_id']);
        });
        Role::findOrCreate('productor', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('lecturas_comunicado');
        Schema::dropIfExists('comunicado_productor');
        Schema::dropIfExists('comunicados');
        Schema::dropIfExists('cuentas_productor');
    }
};
