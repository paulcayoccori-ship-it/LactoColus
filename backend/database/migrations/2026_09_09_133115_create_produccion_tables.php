<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reglas_operativas', function (Blueprint $t): void {
            $t->id();
            $t->string('clave', 80);
            $t->unsignedInteger('version');
            $t->json('valores');
            $t->foreignId('autor_id')->constrained('users')->restrictOnDelete();
            $t->text('motivo');
            $t->timestamps();
            $t->unique(['clave', 'version']);
        });
        Schema::create('auditorias_operativas', function (Blueprint $t): void {
            $t->id();
            $t->string('modulo', 60);
            $t->string('entidad', 100);
            $t->string('accion', 40);
            $t->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $t->json('anteriores');
            $t->json('nuevos');
            $t->text('motivo');
            $t->timestamps();
            $t->index(['modulo', 'created_at']);
            $t->index(['usuario_id', 'created_at']);
        });
        Schema::create('lotes_produccion', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('codigo', 60)->unique();
            $t->dateTime('producido_at');
            $t->string('tipo', 40);
            $t->decimal('litros_cuba', 12, 3);
            $t->unsignedInteger('moldes')->default(0);
            $t->decimal('rendimiento', 14, 6)->nullable();
            $t->json('regla_aplicada');
            $t->foreignId('responsable_id')->constrained('users')->restrictOnDelete();
            $t->string('estado', 20)->default('borrador');
            $t->text('observaciones')->nullable();
            $t->timestamps();
            $t->index(['estado', 'producido_at']);
        });
        Schema::create('usos_recepcion', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('lote_id')->constrained('lotes_produccion')->restrictOnDelete();
            $t->foreignId('recepcion_id')->constrained('recepciones_planta')->restrictOnDelete();
            $t->decimal('litros', 12, 3);
            $t->string('estado', 20);
            $t->timestamps();
            $t->unique(['lote_id', 'recepcion_id']);
            $t->index(['recepcion_id', 'estado']);
        });
        Schema::create('ajustes_produccion', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('lote_id')->constrained('lotes_produccion')->restrictOnDelete();
            $t->integer('delta_moldes');
            $t->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $t->text('motivo');
            $t->timestamps();
        });
        Schema::create('alertas_rendimiento', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('lote_id')->unique()->constrained('lotes_produccion')->restrictOnDelete();
            $t->decimal('rendimiento', 14, 6);
            $t->string('estado', 20);
            $t->json('regla_aplicada');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas_rendimiento');
        Schema::dropIfExists('ajustes_produccion');
        Schema::dropIfExists('usos_recepcion');
        Schema::dropIfExists('lotes_produccion');
        Schema::dropIfExists('auditorias_operativas');
        Schema::dropIfExists('reglas_operativas');
    }
};
