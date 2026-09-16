<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sanciones_calidad', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('analisis_id')->unique()->constrained('analisis_calidad')->restrictOnDelete();
            $t->foreignId('productor_id')->constrained('productores')->restrictOnDelete();
            $t->string('tipo', 25);
            $t->string('estado', 30);
            $t->unsignedInteger('numero_falta');
            $t->decimal('agua_anadida', 10, 4);
            $t->decimal('tarifa_penalizada', 12, 2)->nullable();
            $t->json('regla_aplicada');
            $t->json('fuente_snapshot');
            $t->string('huella', 64);
            $t->boolean('requiere_revision')->default(false);
            $t->boolean('propuesta_perdida')->default(false);
            $t->boolean('propuesta_expulsion')->default(false);
            $t->string('decision_perdida', 20)->default('no_aplica');
            $t->string('decision_expulsion', 20)->default('no_aplica');
            $t->foreignId('decidida_por')->nullable()->constrained('users')->restrictOnDelete();
            $t->dateTime('decidida_at')->nullable();
            $t->text('comentario')->nullable();
            $t->timestamps();
            $t->index(['productor_id', 'estado']);
        });
        Schema::create('asistencias_tecnicas', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('analisis_id')->unique()->constrained('analisis_calidad')->restrictOnDelete();
            $t->foreignId('productor_id')->constrained('productores')->restrictOnDelete();
            $t->string('estado', 20)->default('pendiente');
            $t->foreignId('responsable_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->dateTime('fecha_at')->nullable();
            $t->text('observaciones')->nullable();
            $t->json('criterio_aplicado');
            $t->boolean('requiere_revision')->default(false);
            $t->boolean('fuente_vigente')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencias_tecnicas');
        Schema::dropIfExists('sanciones_calidad');
    }
};
