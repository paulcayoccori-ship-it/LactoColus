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
        Schema::create('perfiles_calidad', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->string('nombre');
            $table->boolean('activo');
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->json('criterios');
            $table->foreignId('creado_por')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('analisis_calidad', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid_publico')->unique();
            $table->uuid('uuid_externo')->unique();
            $table->foreignId('productor_id')->constrained('productores')->restrictOnDelete();
            $table->foreignId('jornada_id')->nullable()->constrained('jornadas_acopio')->restrictOnDelete();
            $table->foreignId('entrega_id')->nullable()->constrained('entregas_acopio')->restrictOnDelete();
            $table->foreignId('ruta_id')->nullable()->constrained('rutas_acopio')->restrictOnDelete();
            $table->foreignId('responsable_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('perfil_id')->nullable()->constrained('perfiles_calidad')->restrictOnDelete();
            $table->dateTime('muestra_at');
            $table->dateTime('sincronizada_at');
            $table->string('equipo', 100)->nullable();
            $table->string('fuente', 20);
            foreach (['grasa', 'proteina', 'lactosa', 'densidad_medida', 'temperatura', 'densidad_corregida', 'solidos_no_grasos', 'ph', 'acidez', 'agua_anadida'] as $field) {
                $table->decimal($field, 10, 4)->nullable();
            }
            $table->json('limites_aplicados');
            $table->json('advertencias');
            $table->string('estado', 30);
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->index(['estado', 'muestra_at']);
            $table->index(['responsable_id', 'muestra_at']);
        });
        Schema::create('auditorias_calidad', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analisis_id')->constrained('analisis_calidad')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->string('accion', 30);
            $table->json('anteriores');
            $table->json('nuevos');
            $table->text('motivo');
            $table->timestamps();
        });
        Role::findOrCreate('calidad', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('auditorias_calidad');
        Schema::dropIfExists('analisis_calidad');
        Schema::dropIfExists('perfiles_calidad');
    }
};
