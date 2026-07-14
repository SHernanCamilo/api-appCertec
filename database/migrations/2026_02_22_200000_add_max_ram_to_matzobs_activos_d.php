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
        Schema::table('matzobs_activos_d', function (Blueprint $table) {
            // Agregar campo max_ram después de tamano_ram (si existe)
            if (Schema::hasColumn('matzobs_activos_d', 'tamano_ram')) {
                $table->decimal('max_ram', 10, 2)->nullable()->after('tamano_ram')
                    ->comment('Capacidad máxima de RAM soportada por el equipo en GB');
            } else {
                $table->decimal('max_ram', 10, 2)->nullable()
                    ->comment('Capacidad máxima de RAM soportada por el equipo en GB');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matzobs_activos_d', function (Blueprint $table) {
            $table->dropColumn('max_ram');
        });
    }
};
