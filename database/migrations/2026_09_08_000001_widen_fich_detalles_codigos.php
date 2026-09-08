<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Amplía las columnas de códigos en fich_detalles.
 *
 * Los códigos de CUPS/grupo/subgrupo provienen de Microsoft Fabric
 * (df.VW_Contract_CUPS) y superan los tamaños originales: p. ej. un subgrupo
 * como "050137" (6 caracteres) no cabía en varchar(4) y provocaba error 422
 * ("subgrupo must not be greater than 4 characters") al guardar los servicios.
 *
 * Se amplían a varchar(20) para cubrir cualquier código real con holgura.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Se usa SQL directo para no depender de doctrine/dbal en la modificación.
        DB::statement('ALTER TABLE `fich_detalles` MODIFY `cups` VARCHAR(20) NULL');
        DB::statement('ALTER TABLE `fich_detalles` MODIFY `grupo` VARCHAR(20) NULL');
        DB::statement('ALTER TABLE `fich_detalles` MODIFY `subgrupo` VARCHAR(20) NULL');
    }

    public function down(): void
    {
        // Reversa a los tamaños originales (puede truncar datos existentes).
        DB::statement('ALTER TABLE `fich_detalles` MODIFY `cups` VARCHAR(10) NULL');
        DB::statement('ALTER TABLE `fich_detalles` MODIFY `grupo` VARCHAR(3) NULL');
        DB::statement('ALTER TABLE `fich_detalles` MODIFY `subgrupo` VARCHAR(4) NULL');
    }
};
