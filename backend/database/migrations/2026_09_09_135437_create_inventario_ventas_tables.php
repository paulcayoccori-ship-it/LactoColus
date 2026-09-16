<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('existencias_queso', function (Blueprint $t): void {
            $t->string('tipo', 40)->primary();
            $t->unsignedBigInteger('moldes')->default(0);
            $t->timestamps();
        });
        Schema::create('clientes', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('nombre', 200);
            $t->string('categoria', 30);
            $t->foreignId('productor_id')->nullable()->unique()->constrained('productores')->restrictOnDelete();
            $t->boolean('activo')->default(true);
            $t->timestamps();
        });
        Schema::create('ventas', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $t->foreignId('productor_id')->nullable()->constrained('productores')->restrictOnDelete();
            $t->json('cliente_snapshot');
            $t->dateTime('vendida_at');
            $t->decimal('subtotal', 14, 2);
            $t->decimal('descuento', 14, 2);
            $t->decimal('total', 14, 2);
            $t->json('tarifa_aplicada');
            $t->boolean('descontar_liquidacion')->default(false);
            $t->string('estado', 20)->default('borrador');
            $t->foreignId('responsable_id')->constrained('users')->restrictOnDelete();
            $t->string('metodo_pago', 80)->nullable();
            $t->dateTime('pagada_at')->nullable();
            $t->foreignId('pagada_por')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('observaciones')->nullable();
            $t->timestamps();
            $t->index(['estado', 'vendida_at']);
        });
        Schema::create('detalles_venta', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('venta_id')->constrained('ventas')->restrictOnDelete();
            $t->string('tipo', 40);
            $t->unsignedInteger('moldes');
            $t->decimal('precio', 12, 2);
            $t->decimal('subtotal', 14, 2);
            $t->timestamps();
            $t->unique(['venta_id', 'tipo']);
        });
        Schema::create('movimientos_inventario', function (Blueprint $t): void {
            $t->id();
            $t->string('clave', 180)->unique();
            $t->string('tipo', 40);
            $t->foreign('tipo')->references('tipo')->on('existencias_queso')->restrictOnDelete();
            $t->string('clase', 25);
            $t->bigInteger('delta');
            $t->unsignedBigInteger('anterior');
            $t->unsignedBigInteger('nuevo');
            $t->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('lote_id')->nullable()->constrained('lotes_produccion')->restrictOnDelete();
            $t->foreignId('venta_id')->nullable()->constrained('ventas')->restrictOnDelete();
            $t->text('motivo');
            $t->timestamps();
            $t->index(['tipo', 'created_at']);
        });
        foreach (['paria_fresco', 'paria_pasteurizado'] as $type) {
            DB::table('existencias_queso')->insert(['tipo' => $type, 'moldes' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
        // Conversión de producción real previa, no datos de demostración.
        DB::table('lotes_produccion')->where('estado', 'finalizado')->orderBy('id')->chunkById(100, function ($lots): void {
            foreach ($lots as $lot) {
                $quantity = $lot->moldes + (int) DB::table('ajustes_produccion')->where('lote_id', $lot->id)->sum('delta_moldes');
                $before = (int) DB::table('existencias_queso')->where('tipo', $lot->tipo)->value('moldes');
                DB::table('existencias_queso')->where('tipo', $lot->tipo)->update(['moldes' => $before + $quantity, 'updated_at' => now()]);
                DB::table('movimientos_inventario')->insert(['clave' => 'lote:'.$lot->uuid.':produccion', 'tipo' => $lot->tipo, 'clase' => 'produccion', 'delta' => $quantity, 'anterior' => $before, 'nuevo' => $before + $quantity, 'usuario_id' => $lot->responsable_id, 'lote_id' => $lot->id, 'motivo' => 'Incorporación de lote finalizado anterior a inventario', 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
        Schema::dropIfExists('detalles_venta');
        Schema::dropIfExists('ventas');
        Schema::dropIfExists('clientes');
        Schema::dropIfExists('existencias_queso');
    }
};
