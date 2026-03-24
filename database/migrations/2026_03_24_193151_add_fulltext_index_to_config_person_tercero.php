<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Índice compuesto para filtros frecuentes (id_empresa + estado + created_at)
        DB::statement('ALTER TABLE config_person_tercero ADD INDEX idx_empresa_estado_created (id_empresa, estado, created_at DESC)');

        // Índice FULLTEXT para búsquedas por nombre (elimina el full scan con LIKE %...%)
        DB::statement('ALTER TABLE config_person_tercero ADD FULLTEXT INDEX ft_nombre (nombre)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE config_person_tercero DROP INDEX idx_empresa_estado_created');
        DB::statement('ALTER TABLE config_person_tercero DROP INDEX ft_nombre');
    }
};
