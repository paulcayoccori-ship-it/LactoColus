<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productores', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 50)->unique();
            $table->char('dni', 8)->unique();
            $table->string('nombres', 150);
            $table->string('apellidos', 150);
            $table->char('celular', 9)->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('direccion')->nullable();
            $table->string('comunidad', 150)->nullable();
            $table->boolean('estado')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productores');
    }
};
