<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('configuraciones_acopio', function (Blueprint $table): void {
            $table->id();
            $table->string('clave', 80)->unique();
            $table->decimal('valor', 10, 3)->nullable();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('recepciones_planta', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid_publico')->unique();
            $table->uuid('uuid_lectura_externa')->nullable()->unique();
            $table->foreignId('jornada_id')->unique()->constrained('jornadas_acopio')->restrictOnDelete();
            $table->foreignId('ruta_id')->constrained('rutas_acopio')->restrictOnDelete();
            $table->foreignId('recolector_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->decimal('litros_campo', 12, 3);
            $table->decimal('litros_planta', 12, 3);
            $table->decimal('diferencia_litros', 12, 3);
            $table->decimal('diferencia_porcentaje', 10, 3)->nullable();
            $table->decimal('tolerancia_porcentaje', 10, 3);
            $table->string('resultado', 30);
            $table->dateTime('recibida_at');
            $table->string('fuente_medicion', 20);
            $table->text('observaciones')->nullable();
            $table->foreignId('registrada_por')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['recibida_at', 'resultado']);
        });

        Schema::create('alertas_conciliacion', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recepcion_id')->unique()->constrained('recepciones_planta')->restrictOnDelete();
            $table->decimal('litros_campo', 12, 3);
            $table->decimal('litros_planta', 12, 3);
            $table->decimal('diferencia_litros', 12, 3);
            $table->decimal('diferencia_porcentaje', 10, 3)->nullable();
            $table->string('estado', 20)->default('pendiente');
            $table->foreignId('revisada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('revisada_at')->nullable();
            $table->text('comentario_resolucion')->nullable();
            $table->timestamps();
            $table->index('estado');
        });

        Schema::create('auditorias_recepcion', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recepcion_id')->constrained('recepciones_planta')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->string('accion', 30);
            $table->json('datos_anteriores')->nullable();
            $table->json('datos_nuevos')->nullable();
            $table->text('motivo');
            $table->timestamps();
            $table->index(['recepcion_id', 'accion']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auditorias_recepcion');
        Schema::dropIfExists('alertas_conciliacion');
        Schema::dropIfExists('recepciones_planta');
        Schema::dropIfExists('configuraciones_acopio');
    }
};
