<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Modifica inv_traz_activo para:
     * 1. Agregar FK tipo_inventario_id (nullable primero, luego NOT NULL si hay datos limpios)
     * 2. Asignar tipo "Inventario General" a registros históricos existentes
     * 3. Eliminar campos obsoletos: novedad_unidad_funcional y observacion_indigo
     */
    public function up(): void
    {
        // ── Paso 1: agregar columna como nullable primero ──────────────────
        Schema::table('inv_traz_activo', function (Blueprint $table) {
            $table->unsignedBigInteger('tipo_inventario_id')
                ->nullable()
                ->after('placa')
                ->comment('Tipo de inventario: General (anual) o Aleatorio (mensual)');
        });

        // ── Paso 2: asignar tipo "Inventario General" a los registros existentes ──
        $tipoGeneral = DB::table('inv_tipos_inventario')
            ->where('nombre', 'Inventario General')
            ->value('id');

        if ($tipoGeneral) {
            DB::table('inv_traz_activo')
                ->whereNull('tipo_inventario_id')
                ->update(['tipo_inventario_id' => $tipoGeneral]);
        }

        // ── Paso 3: agregar FK y convertir a NOT NULL ─────────────────────
        Schema::table('inv_traz_activo', function (Blueprint $table) {
            // Cambiar a NOT NULL ahora que todos los registros tienen valor
            $table->unsignedBigInteger('tipo_inventario_id')
                ->nullable(false)
                ->change();

            // FK
            $table->foreign('tipo_inventario_id', 'inv_traz_activo_tipo_inventario_id_foreign')
                ->references('id')
                ->on('inv_tipos_inventario')
                ->onDelete('restrict');

            // Índice optimizado para validación de periodicidad
            $table->index(['placa', 'tipo_inventario_id', 'created_at'], 'idx_traz_periodicidad');
        });

        // ── Paso 4: eliminar columnas obsoletas ────────────────────────────
        Schema::table('inv_traz_activo', function (Blueprint $table) {
            if (Schema::hasColumn('inv_traz_activo', 'novedad_unidad_funcional')) {
                $table->dropColumn('novedad_unidad_funcional');
            }
            if (Schema::hasColumn('inv_traz_activo', 'observacion_indigo')) {
                $table->dropColumn('observacion_indigo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inv_traz_activo', function (Blueprint $table) {
            // Restaurar índices y FK
            try { $table->dropIndex('idx_traz_periodicidad'); } catch (\Throwable) {}
            try { $table->dropForeign('inv_traz_activo_tipo_inventario_id_foreign'); } catch (\Throwable) {}

            // Restaurar columnas eliminadas
            $table->string('novedad_unidad_funcional', 255)->nullable()->after('novedad_estado_fisico');
            $table->text('observacion_indigo')->nullable()->after('observacion');

            // Eliminar la columna agregada
            $table->dropColumn('tipo_inventario_id');
        });
    }
};
