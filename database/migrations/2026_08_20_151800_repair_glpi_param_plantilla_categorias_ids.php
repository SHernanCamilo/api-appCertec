<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('glpi_param_plantilla_categorias')) {
            return;
        }

        $this->asignarIdsUnicos();
        $this->restaurarClavePrimaria();
        $this->reconstruirPadres();
        $this->restaurarIndices();
    }

    public function down(): void
    {
        // Reparación de datos; no se revierte.
    }

    private function asignarIdsUnicos(): void
    {
        $duplicados = (int) DB::table('glpi_param_plantilla_categorias')
            ->selectRaw('COUNT(*) - COUNT(DISTINCT id) as duplicados')
            ->value('duplicados');
        $ceros = (int) DB::table('glpi_param_plantilla_categorias')->where('id', 0)->count();

        if ($duplicados === 0 && $ceros === 0) {
            return;
        }

        DB::statement('SET @row_id := 0');
        DB::statement('
            UPDATE glpi_param_plantilla_categorias
            SET id = (@row_id := @row_id + 1)
            ORDER BY plantilla_id, COALESCE(ruta_completa, nombre, categoria), created_at, id
        ');
    }

    private function restaurarClavePrimaria(): void
    {
        $pk = DB::select("SHOW KEYS FROM glpi_param_plantilla_categorias WHERE Key_name = 'PRIMARY'");
        if ($pk === []) {
            DB::statement('ALTER TABLE glpi_param_plantilla_categorias MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY');
        } else {
            DB::statement('ALTER TABLE glpi_param_plantilla_categorias MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
        }

        $maxId = (int) DB::table('glpi_param_plantilla_categorias')->max('id');
        DB::statement('ALTER TABLE glpi_param_plantilla_categorias AUTO_INCREMENT = '.($maxId + 1));
    }

    private function reconstruirPadres(): void
    {
        $filas = DB::table('glpi_param_plantilla_categorias')
            ->orderBy('plantilla_id')
            ->orderBy('nivel')
            ->get(['id', 'plantilla_id', 'nivel', 'ruta_completa']);

        $indice = [];
        foreach ($filas as $fila) {
            $ruta = trim((string) $fila->ruta_completa);
            if ($ruta !== '') {
                $indice[$fila->plantilla_id.'|'.$ruta] = (int) $fila->id;
            }
        }

        foreach ($filas as $fila) {
            $parentId = null;
            $ruta = trim((string) $fila->ruta_completa);
            $nivel = (int) $fila->nivel;

            if ($nivel > 1 && $ruta !== '') {
                $pos = mb_strrpos($ruta, ' > ');
                if ($pos !== false) {
                    $rutaPadre = trim(mb_substr($ruta, 0, $pos));
                    $parentId = $indice[$fila->plantilla_id.'|'.$rutaPadre] ?? null;
                }
            }

            if ((int) $fila->id === (int) $parentId) {
                $parentId = null;
            }

            DB::table('glpi_param_plantilla_categorias')
                ->where('id', $fila->id)
                ->update(['parent_id' => $parentId]);
        }
    }

    private function restaurarIndices(): void
    {
        $this->crearIndiceSiFalta(
            'idx_glpi_param_cat_prioridad',
            'ALTER TABLE glpi_param_plantilla_categorias ADD INDEX idx_glpi_param_cat_prioridad (plantilla_id, prioridad)'
        );
        $this->crearIndiceSiFalta(
            'idx_glpi_param_cat_parent',
            'ALTER TABLE glpi_param_plantilla_categorias ADD INDEX idx_glpi_param_cat_parent (plantilla_id, parent_id)'
        );

        if (! $this->existeForeign('fk_glpi_param_cat_plt') && Schema::hasTable('glpi_param_plantillas')) {
            DB::statement('
                ALTER TABLE glpi_param_plantilla_categorias
                ADD CONSTRAINT fk_glpi_param_cat_plt
                FOREIGN KEY (plantilla_id) REFERENCES glpi_param_plantillas(id) ON DELETE CASCADE
            ');
        }

        if (! $this->existeForeign('fk_glpi_param_cat_parent')) {
            DB::statement('
                ALTER TABLE glpi_param_plantilla_categorias
                ADD CONSTRAINT fk_glpi_param_cat_parent
                FOREIGN KEY (parent_id) REFERENCES glpi_param_plantilla_categorias(id) ON DELETE SET NULL
            ');
        }
    }

    private function crearIndiceSiFalta(string $nombre, string $sql): void
    {
        $existe = DB::select('SHOW INDEX FROM glpi_param_plantilla_categorias WHERE Key_name = ?', [$nombre]);
        if ($existe === []) {
            DB::statement($sql);
        }
    }

    private function existeForeign(string $nombre): bool
    {
        $filas = DB::select(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ?',
            ['glpi_param_plantilla_categorias', $nombre, 'FOREIGN KEY']
        );

        return $filas !== [];
    }
};
