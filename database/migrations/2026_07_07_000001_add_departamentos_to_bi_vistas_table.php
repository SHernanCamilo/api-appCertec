<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bi_vistas')) {
            return;
        }

        Schema::table('bi_vistas', function (Blueprint $table) {
            if (!Schema::hasColumn('bi_vistas', 'departamentos')) {
                $table->json('departamentos')->nullable()->after('descripcion')
                    ->comment('Códigos de sede permitidos: MA, NAL, FLA… Vacío = todos');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('bi_vistas')) {
            return;
        }

        Schema::table('bi_vistas', function (Blueprint $table) {
            if (Schema::hasColumn('bi_vistas', 'departamentos')) {
                $table->dropColumn('departamentos');
            }
        });
    }
};
