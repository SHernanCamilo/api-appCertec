<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega es_obligatorio a anti_conceptos.
 *
 * Transporte  → es_obligatorio = true  (siempre se incluye)
 * Alimentación → es_obligatorio = false (el usuario decide)
 * Hospedaje   → es_obligatorio = false (el usuario decide)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anti_conceptos', function (Blueprint $table) {
            $table->boolean('es_obligatorio')
                  ->default(false)
                  ->comment('true = se incluye siempre (transporte), false = el usuario decide (alimentación, hospedaje)')
                  ->after('id_modalidad');

            $table->index('es_obligatorio');
        });
    }

    public function down(): void
    {
        Schema::table('anti_conceptos', function (Blueprint $table) {
            $table->dropIndex(['es_obligatorio']);
            $table->dropColumn('es_obligatorio');
        });
    }
};
