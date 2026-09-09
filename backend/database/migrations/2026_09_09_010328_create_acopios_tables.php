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
        Schema::create('jornadas_acopio', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid_publico')->unique();
            $table->foreignId('ruta_id')->constrained('rutas_acopio')->restrictOnDelete();
            $table->foreignId('recolector_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('fecha_operativa');
            $table->string('turno', 30);
            $table->dateTime('iniciada_at')->nullable();
            $table->dateTime('cerrada_at')->nullable();
            $table->string('estado', 20)->default('abierta');
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->unique(['ruta_id', 'fecha_operativa', 'turno']);
            $table->index(['fecha_operativa', 'estado']);
        });
        Schema::create('entregas_acopio', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid_cliente')->unique();
            $table->foreignId('jornada_id')->constrained('jornadas_acopio')->restrictOnDelete();
            $table->foreignId('ruta_id')->constrained('rutas_acopio')->restrictOnDelete();
            $table->foreignId('productor_id')->constrained('productores')->restrictOnDelete();
            $table->foreignId('recolector_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->decimal('litros', 10, 3);
            $table->dateTime('recolectada_at');
            $table->text('observacion')->nullable();
            $table->dateTime('sincronizada_at')->nullable();
            $table->timestamps();
            $table->unique(['jornada_id', 'productor_id']);
            $table->index(['jornada_id', 'recolectada_at']);
        });
        Schema::create('auditorias_acopio', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('jornada_id')->nullable()->constrained('jornadas_acopio')->nullOnDelete();
            $table->foreignId('entrega_id')->nullable()->constrained('entregas_acopio')->nullOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->string('accion', 30);
            $table->json('datos_anteriores')->nullable();
            $table->json('datos_nuevos')->nullable();
            $table->text('motivo')->nullable();
            $table->timestamps();
            $table->index(['jornada_id', 'accion']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auditorias_acopio');
        Schema::dropIfExists('entregas_acopio');
        Schema::dropIfExists('jornadas_acopio');
    }
};
