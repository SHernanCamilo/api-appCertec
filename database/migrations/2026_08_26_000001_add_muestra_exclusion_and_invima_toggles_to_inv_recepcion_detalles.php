<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes post-análisis VPS (2026-08-26):
 *   - Agrega columna `muestra_exclusion` para persistir si el producto
 *     está excluido de muestreo (100% inspección). Al recargar la recepción
 *     ya no cambia el valor de muestra_poblacion.
 *   - Agrega columnas invima_override_manual y invima_observaciones para
 *     el flujo "estado Invima NO lo encontró pero sí confirmado por usuario".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_recepcion_detalles', function (Blueprint $table) {
            if (!Schema::hasColumn('inv_recepcion_detalles', 'muestra_exclusion')) {
                $table->boolean('muestra_exclusion')
                    ->default(false)
                    ->after('muestra_poblacion')
                    ->comment('TRUE si el producto está excluido de muestreo (100% inspección)');
            }
            if (!Schema::hasColumn('inv_recepcion_detalles', 'invima_observaciones')) {
                $table->string('invima_observaciones', 255)
                    ->nullable()
                    ->after('estado_invima')
                    ->comment('Justificación cuando el usuario hace override manual de estado Invima');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inv_recepcion_detalles', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('inv_recepcion_detalles', 'muestra_exclusion'))   $cols[] = 'muestra_exclusion';
            if (Schema::hasColumn('inv_recepcion_detalles', 'invima_observaciones')) $cols[] = 'invima_observaciones';
            if (!empty($cols)) $table->dropColumn($cols);
        });
    }
};
