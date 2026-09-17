<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna `ans_nombre` a glpi_param_plantilla_categorias.
 *
 * El modelo (GlpiParamPlantillaCategoria), el controller (guardarNodo) y el
 * remap posterior (2026_08_31_150000) ya usan esta columna, pero ninguna
 * migracion la creaba. En produccion eso provoca:
 *   SQLSTATE[42S22]: Unknown column 'ans_nombre' in 'INSERT INTO'
 *
 * La guarda con hasColumn hace la migracion idempotente: si un entorno ya la
 * tiene (creada a mano), no falla al re-ejecutar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('glpi_param_plantilla_categorias')) {
            return;
        }

        if (Schema::hasColumn('glpi_param_plantilla_categorias', 'ans_nombre')) {
            return;
        }

        Schema::table('glpi_param_plantilla_categorias', function (Blueprint $table): void {
            // Nombre del ANS asociado a la categoria hoja. Nullable porque las
            // categorias padre (con hijas) no llevan ANS directo.
            $table->string('ans_nombre', 150)->nullable()->after('prioridad');
        });
    }

    public function down(): void
    {
        if (
            Schema::hasTable('glpi_param_plantilla_categorias')
            && Schema::hasColumn('glpi_param_plantilla_categorias', 'ans_nombre')
        ) {
            Schema::table('glpi_param_plantilla_categorias', function (Blueprint $table): void {
                $table->dropColumn('ans_nombre');
            });
        }
    }
};
