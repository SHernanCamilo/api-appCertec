<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persiste la descripción del CUPS / grupo / subgrupo en el propio detalle.
 *
 * Motivo: el maestro de CUPS vive ahora en Microsoft Fabric (df.VW_Contract_CUPS),
 * y la tabla local `fich_cups` quedó vacía en producción. La vista
 * `v_fich_detalles_completo` resolvía la descripción con un JOIN a `fich_cups`,
 * por lo que en el PDF la columna "Descripción" salía solo con el código.
 *
 * Guardando la descripción al momento de agregar el ítem (el frontend ya la
 * tiene del autocomplete/select de Fabric), el detalle queda autosuficiente y
 * el PDF muestra el nombre sin depender de `fich_cups`. La vista usa estas
 * columnas con prioridad y cae al JOIN solo si están vacías.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fich_detalles', function ($table): void {
            if (! Schema::hasColumn('fich_detalles', 'cups_descripcion')) {
                $table->string('cups_descripcion', 500)->nullable()->after('cups');
            }
            if (! Schema::hasColumn('fich_detalles', 'grupo_descripcion')) {
                $table->string('grupo_descripcion', 300)->nullable()->after('grupo');
            }
            if (! Schema::hasColumn('fich_detalles', 'subgrupo_descripcion')) {
                $table->string('subgrupo_descripcion', 300)->nullable()->after('subgrupo');
            }
        });

        // Redefinir la vista para PRIORIZAR la descripción persistida en el
        // detalle (COALESCE) y caer al JOIN con fich_cups solo si está vacía.
        DB::unprepared('DROP VIEW IF EXISTS v_fich_detalles_completo');
        DB::unprepared(<<<'SQL'
CREATE VIEW v_fich_detalles_completo AS
SELECT
    d.id AS id,
    d.id_ficha AS id_ficha,
    d.tipo_liquidacion AS tipo_liquidacion,
    d.tipo_servicio AS tipo_servicio,
    d.id_tipo_servicio AS id_tipo_servicio,
    ts.descripcion AS tipo_servicio_descripcion,
    d.cups AS cups,
    COALESCE(NULLIF(d.cups_descripcion, ''), c.desc_subcat) AS cups_descripcion,
    c.resolucion AS cups_resolucion,
    d.grupo AS grupo,
    COALESCE(NULLIF(d.grupo_descripcion, ''), cg.desc_grup) AS grupo_descripcion,
    d.subgrupo AS subgrupo,
    COALESCE(NULLIF(d.subgrupo_descripcion, ''), cs.desc_subg) AS subgrupo_descripcion,
    d.forma_pago AS forma_pago,
    d.homologo AS homologo,
    h.tipo_manual AS homologo_tipo_manual,
    h.desc_manual AS homologo_descripcion,
    h.uvr_grupo AS homologo_uvr_grupo,
    d.variacion AS variacion,
    d.valor AS valor,
    d.id_obs_item AS id_obs_item,
    oi.descripcion AS obs_item_descripcion,
    d.novedad AS novedad,
    d.created_at AS created_at,
    d.updated_at AS updated_at
FROM fich_detalles d
    LEFT JOIN fich_tipos_servicio ts ON ts.id = d.id_tipo_servicio
    LEFT JOIN fich_obs_items oi ON oi.id = d.id_obs_item
    LEFT JOIN fich_cups c ON c.subcategoria = d.cups AND c.es_vigente = 1
    LEFT JOIN fich_cups cg ON cg.grupo = d.grupo AND cg.es_vigente = 1 AND cg.subgrupo IS NULL
    LEFT JOIN fich_cups cs ON cs.subgrupo = d.subgrupo AND cs.es_vigente = 1
    LEFT JOIN fich_homologos h ON h.code_manual = d.homologo
SQL);
    }

    public function down(): void
    {
        // Restaurar la vista sin las columnas persistidas (JOIN puro).
        DB::unprepared('DROP VIEW IF EXISTS v_fich_detalles_completo');
        DB::unprepared(<<<'SQL'
CREATE VIEW v_fich_detalles_completo AS
SELECT
    d.id AS id, d.id_ficha AS id_ficha, d.tipo_liquidacion AS tipo_liquidacion,
    d.tipo_servicio AS tipo_servicio, d.id_tipo_servicio AS id_tipo_servicio,
    ts.descripcion AS tipo_servicio_descripcion, d.cups AS cups,
    c.desc_subcat AS cups_descripcion, c.resolucion AS cups_resolucion,
    d.grupo AS grupo, cg.desc_grup AS grupo_descripcion, d.subgrupo AS subgrupo,
    cs.desc_subg AS subgrupo_descripcion, d.forma_pago AS forma_pago, d.homologo AS homologo,
    h.tipo_manual AS homologo_tipo_manual, h.desc_manual AS homologo_descripcion,
    h.uvr_grupo AS homologo_uvr_grupo, d.variacion AS variacion, d.valor AS valor,
    d.id_obs_item AS id_obs_item, oi.descripcion AS obs_item_descripcion,
    d.novedad AS novedad, d.created_at AS created_at, d.updated_at AS updated_at
FROM fich_detalles d
    LEFT JOIN fich_tipos_servicio ts ON ts.id = d.id_tipo_servicio
    LEFT JOIN fich_obs_items oi ON oi.id = d.id_obs_item
    LEFT JOIN fich_cups c ON c.subcategoria = d.cups AND c.es_vigente = 1
    LEFT JOIN fich_cups cg ON cg.grupo = d.grupo AND cg.es_vigente = 1 AND cg.subgrupo IS NULL
    LEFT JOIN fich_cups cs ON cs.subgrupo = d.subgrupo AND cs.es_vigente = 1
    LEFT JOIN fich_homologos h ON h.code_manual = d.homologo
SQL);

        Schema::table('fich_detalles', function ($table): void {
            foreach (['cups_descripcion', 'grupo_descripcion', 'subgrupo_descripcion'] as $col) {
                if (Schema::hasColumn('fich_detalles', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
