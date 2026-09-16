<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_traslado', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('productor_id')->constrained('productores')->restrictOnDelete();
            $t->foreignId('ruta_actual_id')->constrained('rutas_acopio')->restrictOnDelete();
            $t->foreignId('ruta_solicitada_id')->constrained('rutas_acopio')->restrictOnDelete();
            $t->foreignId('solicitante_id')->constrained('users')->restrictOnDelete();
            $t->dateTime('solicitada_at');
            $t->date('fecha_efectiva');
            $t->text('motivo');
            $t->string('estado', 20)->default('pendiente');
            $t->json('regla_aplicada');
            $t->foreignId('decidida_por')->nullable()->constrained('users')->restrictOnDelete();
            $t->dateTime('decidida_at')->nullable();
            $t->text('comentario')->nullable();
            $t->dateTime('aplicada_at')->nullable();
            $t->text('ultimo_error')->nullable();
            $t->unsignedBigInteger('productor_pendiente')->nullable()->storedAs("CASE WHEN estado IN ('pendiente','aprobada') THEN productor_id ELSE NULL END");
            $t->unique('productor_pendiente');
            $t->timestamps();
            $t->index(['estado', 'fecha_efectiva']);
        });
        Schema::create('historial_traslados', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('solicitud_id')->unique()->constrained('solicitudes_traslado')->restrictOnDelete();
            $t->foreignId('productor_id')->constrained('productores')->restrictOnDelete();
            $t->foreignId('ruta_anterior_id')->constrained('rutas_acopio')->restrictOnDelete();
            $t->foreignId('ruta_nueva_id')->constrained('rutas_acopio')->restrictOnDelete();
            $t->unsignedInteger('orden_anterior');
            $t->unsignedInteger('orden_nuevo');
            $t->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $t->dateTime('aplicada_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historial_traslados');
        Schema::dropIfExists('solicitudes_traslado');
    }
};
