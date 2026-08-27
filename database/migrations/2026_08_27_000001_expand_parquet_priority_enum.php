<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Expande el ENUM de priority en bi_parquet_config para aceptar los valores
 * que usa Graph-Fabric: realtime, operativo, analitico.
 *
 * Antes: realtime, high, medium, low, manual (nomenclatura Laravel)
 * Ahora: se agregan operativo, analitico (nomenclatura Graph-Fabric)
 *
 * Se mantienen los antiguos por compatibilidad; el rebalanceo migra los datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE bi_parquet_config
            MODIFY COLUMN priority
            ENUM('realtime','high','medium','low','manual','operativo','analitico')
            NOT NULL DEFAULT 'medium'
        ");
    }

    public function down(): void
    {
        // Revertir: mapear operativo→high, analitico→low antes de reducir el enum
        DB::statement("UPDATE bi_parquet_config SET priority = 'high' WHERE priority = 'operativo'");
        DB::statement("UPDATE bi_parquet_config SET priority = 'low' WHERE priority = 'analitico'");
        DB::statement("
            ALTER TABLE bi_parquet_config
            MODIFY COLUMN priority
            ENUM('realtime','high','medium','low','manual')
            NOT NULL DEFAULT 'medium'
        ");
    }
};
