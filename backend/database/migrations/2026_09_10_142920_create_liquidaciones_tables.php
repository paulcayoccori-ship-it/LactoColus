<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periodos_liquidacion', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->date('desde');
            $t->date('hasta');
            $t->date('pago_previsto');
            $t->string('estado', 20)->default('abierto');
            $t->json('regla_aplicada')->nullable();
            $t->foreignId('creado_por')->constrained('users')->restrictOnDelete();
            $t->dateTime('cerrado_at')->nullable();
            $t->foreignId('cerrado_por')->nullable()->constrained('users')->restrictOnDelete();
            $t->dateTime('aprobado_at')->nullable();
            $t->foreignId('aprobado_por')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('motivo');
            $t->timestamps();
            $t->date('inicio_vigente')->nullable()->storedAs("CASE WHEN estado <> 'anulado' THEN desde ELSE NULL END")->unique();
            $t->index(['estado', 'desde', 'hasta']);
        });
        Schema::create('liquidaciones', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('periodo_id')->constrained('periodos_liquidacion')->restrictOnDelete();
            $t->foreignId('productor_id')->constrained('productores')->restrictOnDelete();
            $t->json('productor_snapshot');
            $t->json('litros_diarios');
            $t->decimal('litros_total', 14, 3);
            $t->decimal('precio_litro', 12, 2);
            $t->decimal('importe_bruto', 14, 2);
            $t->decimal('penalizaciones', 14, 2);
            $t->decimal('descuentos_queso', 14, 2);
            $t->decimal('total_base', 14, 2);
            $t->json('regla_aplicada');
            $t->json('detalle_calculo');
            $t->boolean('perdida_liquidacion')->default(false);
            $t->string('estado', 20)->default('calculada');
            $t->timestamps();
            $t->unique(['periodo_id', 'productor_id']);
        });
        Schema::create('periodo_productores', function (Blueprint $t): void {
            $t->foreignId('periodo_id')->constrained('periodos_liquidacion')->restrictOnDelete();
            $t->foreignId('productor_id')->constrained('productores')->restrictOnDelete();
            $t->json('snapshot');
            $t->primary(['periodo_id', 'productor_id']);
        });
        foreach (['entregas' => 'entregas_acopio', 'sanciones' => 'sanciones_calidad'] as $suffix => $source) {
            Schema::create('periodo_'.$suffix, function (Blueprint $t) use ($source): void {
                $t->id();
                $t->foreignId('periodo_id')->constrained('periodos_liquidacion')->restrictOnDelete();
                $t->foreignId('fuente_id')->constrained($source)->restrictOnDelete();
                $t->foreignId('productor_id')->constrained('productores')->restrictOnDelete();
                $t->json('snapshot');
                $t->unique(['periodo_id', 'fuente_id']);
            });
        }
        Schema::create('periodo_ventas', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('periodo_id')->constrained('periodos_liquidacion')->restrictOnDelete();
            $t->foreignId('venta_id')->constrained('ventas')->restrictOnDelete();
            $t->foreignId('productor_id')->constrained('productores')->restrictOnDelete();
            $t->json('snapshot');
            $t->boolean('vigente')->default(true);
            $t->unsignedBigInteger('venta_vigente')->nullable()->storedAs('CASE WHEN vigente = 1 THEN venta_id ELSE NULL END')->unique();
            $t->unique(['periodo_id', 'venta_id']);
        });
        Schema::create('ajustes_liquidacion', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('liquidacion_id')->constrained('liquidaciones')->restrictOnDelete();
            $t->foreignId('origen_liquidacion_id')->nullable()->constrained('liquidaciones')->restrictOnDelete();
            $t->string('tipo', 15);
            $t->decimal('importe', 14, 2);
            $t->string('estado', 20)->default('pendiente');
            $t->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('decidido_por')->nullable()->constrained('users')->restrictOnDelete();
            $t->dateTime('decidido_at')->nullable();
            $t->text('motivo');
            $t->text('comentario')->nullable();
            $t->timestamps();
        });
        Schema::create('pagos_liquidacion', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid_externo')->unique();
            $t->foreignId('liquidacion_id')->unique()->constrained('liquidaciones')->restrictOnDelete();
            $t->decimal('importe', 14, 2);
            $t->string('metodo', 80);
            $t->dateTime('pagado_at');
            $t->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $t->json('snapshot');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['pagos_liquidacion', 'ajustes_liquidacion', 'periodo_ventas', 'periodo_sanciones', 'periodo_entregas', 'periodo_productores', 'liquidaciones', 'periodos_liquidacion'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
