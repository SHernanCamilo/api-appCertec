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
        if (Schema::hasColumn('matzobs_activos_d', 'edad')) {
            Schema::table('matzobs_activos_d', function (Blueprint $table) {
                // Cambiar el campo edad de integer a decimal(4,1)
                $table->decimal('edad', 4, 1)->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matzobs_activos_d', function (Blueprint $table) {
            // Revertir a integer
            $table->integer('edad')->nullable()->change();
        });
    }
};
