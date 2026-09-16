<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analisis_calidad', function (Blueprint $t): void {
            $t->decimal('solidos_totales', 10, 4)->nullable();
        });
        Schema::create('calculos_ranking', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('huella', 64)->unique();
            $t->string('tipo', 15);
            $t->date('desde');
            $t->date('hasta');
            $t->foreignId('ruta_id')->nullable()->constrained('rutas_acopio')->restrictOnDelete();
            $t->json('regla_aplicada');
            $t->string('algoritmo', 60);
            $t->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $t->boolean('vigente')->default(true);
            $t->timestamps();
            $t->index(['tipo', 'desde', 'hasta', 'vigente']);
        });
        Schema::create('resultados_ranking', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('calculo_id')->constrained('calculos_ranking')->restrictOnDelete();
            $t->foreignId('productor_id')->constrained('productores')->restrictOnDelete();
            $t->json('productor_snapshot');
            $t->json('rutas_snapshot');
            $t->decimal('puntuacion', 7, 4)->nullable();
            $t->unsignedInteger('posicion')->nullable();
            $t->json('detalle');
            $t->string('estado', 25);
            $t->timestamps();
            $t->unique(['calculo_id', 'productor_id']);
        });
        Schema::create('fuentes_ranking', function (Blueprint $t): void {
            $t->foreignId('calculo_id')->constrained('calculos_ranking')->restrictOnDelete();
            $t->foreignId('analisis_id')->constrained('analisis_calidad')->restrictOnDelete();
            $t->json('snapshot');
            $t->primary(['calculo_id', 'analisis_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuentes_ranking');
        Schema::dropIfExists('resultados_ranking');
        Schema::dropIfExists('calculos_ranking');
        Schema::table('analisis_calidad', fn (Blueprint $t) => $t->dropColumn('solidos_totales'));
    }
};
