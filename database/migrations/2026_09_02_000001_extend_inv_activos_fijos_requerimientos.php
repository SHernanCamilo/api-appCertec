<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extiende el módulo de activos fijos para cubrir los requerimientos:
 *
 *  - Req. 3: localizacion_original (localización de Indigo al momento de la toma,
 *            conservada para trazabilidad aunque el maestro cambie).
 *  - Req. 7: resultado_inventario (clasificación con_novedades/sin_novedades/externo)
 *            persistida para filtros y reportes rápidos.
 *  - Req. 5: regla_validacion (JSON) en inv_tipos_inventario para configurar el
 *            comportamiento sin quemar periodicidades en código.
 *  - No funcional (auditoría): actualizado_por.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_traz_activo', function (Blueprint $table) {
            if (!Schema::hasColumn('inv_traz_activo', 'localizacion_original')) {
                $table->string('localizacion_original', 255)
                    ->nullable()
                    ->after('novedad_localizacion')
                    ->comment('Localización que tenía el activo en Indigo al momento de la toma (Req. 3, solo referencia).');
            }

            if (!Schema::hasColumn('inv_traz_activo', 'resultado_inventario')) {
                $table->string('resultado_inventario', 30)
                    ->nullable()
                    ->after('novedad_estado_fisico')
                    ->comment('con_novedades | sin_novedades | externo (Req. 7).');
                $table->index('resultado_inventario', 'idx_traz_resultado_inventario');
            }

            if (!Schema::hasColumn('inv_traz_activo', 'actualizado_por')) {
                $table->unsignedBigInteger('actualizado_por')
                    ->nullable()
                    ->after('registrado_por')
                    ->comment('Usuario que modificó por última vez el registro (auditoría).');
                $table->foreign('actualizado_por', 'inv_traz_activo_actualizado_por_foreign')
                    ->references('id')->on('users')->onDelete('set null');
            }
        });

        Schema::table('inv_tipos_inventario', function (Blueprint $table) {
            if (!Schema::hasColumn('inv_tipos_inventario', 'regla_validacion')) {
                $table->json('regla_validacion')
                    ->nullable()
                    ->after('periodicidad')
                    ->comment('Regla configurable de validación (Req. 5): permite comportamiento futuro sin quemar código.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inv_traz_activo', function (Blueprint $table) {
            try { $table->dropForeign('inv_traz_activo_actualizado_por_foreign'); } catch (\Throwable) {}
            try { $table->dropIndex('idx_traz_resultado_inventario'); } catch (\Throwable) {}

            foreach (['localizacion_original', 'resultado_inventario', 'actualizado_por'] as $col) {
                if (Schema::hasColumn('inv_traz_activo', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('inv_tipos_inventario', function (Blueprint $table) {
            if (Schema::hasColumn('inv_tipos_inventario', 'regla_validacion')) {
                $table->dropColumn('regla_validacion');
            }
        });
    }
};
