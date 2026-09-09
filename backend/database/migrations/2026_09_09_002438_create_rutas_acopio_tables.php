<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rutas_acopio', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 50)->unique();
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            $table->boolean('estado')->default(true)->index();
            $table->foreignId('recolector_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('ruta_productor', function (Blueprint $table): void {
            $table->foreignId('ruta_id')->constrained('rutas_acopio')->restrictOnDelete();
            $table->foreignId('productor_id')->primary()->constrained('productores')->restrictOnDelete();
            $table->unsignedInteger('orden');
            $table->unique(['ruta_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ruta_productor');
        Schema::dropIfExists('rutas_acopio');
    }
};
