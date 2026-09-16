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
        Schema::table('entregas_acopio', function (Blueprint $table): void {
            $table->boolean('no_entrego')->default(false)->after('productor_id');
            $table->decimal('litros', 10, 3)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('entregas_acopio', function (Blueprint $table): void {
            $table->decimal('litros', 10, 3)->nullable(false)->change();
            $table->dropColumn('no_entrego');
        });
    }
};
