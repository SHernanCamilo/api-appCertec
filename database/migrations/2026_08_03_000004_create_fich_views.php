<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 1 — Vistas SQL del módulo Fichas Técnicas.
 *
 * Motivación (refactor del legacy):
 *  - `config_borradores.php`, `config_proc.php`, `config_rech.php` y
 *    `config_finalizadas.php` repetían el MISMO bloque de 6 INNER JOIN en
 *    12 funciones distintas, cambiando solo el WHERE. Se centraliza en
 *    `v_fich_fichas_listado`.
 *  - La función `stats()` armaba 10 subconsultas LEFT JOIN sobre `ficha`
 *    (10 escaneos de tabla) para un simple conteo por estado. Se reemplaza
 *    por `v_fich_dashboard_sucursal`, que resuelve con un único GROUP BY.
 *  - Los PDF (`ficha_pdf.php`, `ficha_os_pdf.php`, `pdf.php`, `pdf_os.php`)
 *    duplicaban el JOIN detalle↔CUPS↔obs↔homólogos. Se centraliza en
 *    `v_fich_detalles_completo`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 1. Listado maestro de fichas
        //    Reemplaza los 6 INNER JOIN repetidos en todos los config_*.php
        // ─────────────────────────────────────────────────────────────────
        DB::statement('DROP VIEW IF EXISTS v_fich_fichas_listado');
        DB::statement(<<<'SQL'
            CREATE VIEW v_fich_fichas_listado AS
            SELECT
                f.id,
                f.consecutivo,
                f.id_padre,
                f.version,
                f.vlr_contrato,
                f.fecha_ini,
                f.fecha_fin,
                f.fecha_reg,
                f.obs_os,
                f.novedad,
                f.total_detalles,
                f.valor_total_detalles,
                f.total_profesionales,
                f.created_at,
                f.updated_at,

                f.id_estado,
                e.codigo          AS estado_codigo,
                e.descripcion     AS estado_descripcion,
                e.tipo            AS estado_tipo,
                e.color_hex       AS estado_color,
                e.es_editable     AS estado_es_editable,
                e.es_final        AS estado_es_final,

                f.id_agremiacion,
                a.nombre          AS agremiacion_nombre,
                a.nit             AS agremiacion_nit,

                f.id_especialidad,
                esp.descripcion   AS especialidad_descripcion,
                esp.perfil        AS especialidad_perfil,

                f.id_objeto_contrato,
                obj.descripcion   AS objeto_contrato_descripcion,

                f.id_empresa,
                emp.nombre        AS empresa_nombre,
                emp.prefijo       AS empresa_prefijo,

                f.id_sucursal,
                suc.nombre        AS sucursal_nombre,
                f.sucursal_legacy,

                f.id_user_reg,
                ug.name           AS generador_nombre,
                ug.email          AS generador_email,

                f.user_autoriza_id,
                f.fecha_autoriza,
                f.obs_autoriza,
                ua.name           AS autorizador_nombre,
                ua.email          AS autorizador_email,

                f.user_aprueba_id,
                f.fecha_aprueba,
                f.obs_aprueba,
                up.name           AS aprobador_nombre,
                up.email          AS aprobador_email,

                DATEDIFF(f.fecha_fin, CURDATE()) AS dias_restantes,
                CASE
                    WHEN f.fecha_fin <  CURDATE() THEN 'VENCIDA'
                    WHEN DATEDIFF(f.fecha_fin, CURDATE()) <= 10 THEN 'CRITICA'
                    WHEN DATEDIFF(f.fecha_fin, CURDATE()) <= 15 THEN 'ALERTA'
                    WHEN DATEDIFF(f.fecha_fin, CURDATE()) <= 30 THEN 'PROXIMA'
                    ELSE 'VIGENTE'
                END AS vigencia_estado
            FROM fich_fichas f
            INNER JOIN fich_estados            e   ON e.id   = f.id_estado
            INNER JOIN fich_agremiaciones      a   ON a.id   = f.id_agremiacion
            INNER JOIN fich_especialidades     esp ON esp.id = f.id_especialidad
            INNER JOIN fich_objetos_contrato   obj ON obj.id = f.id_objeto_contrato
            INNER JOIN users                   ug  ON ug.id  = f.id_user_reg
            LEFT  JOIN ent_empresas            emp ON emp.id = f.id_empresa
            LEFT  JOIN config_ubi_sucursales   suc ON suc.id = f.id_sucursal
            LEFT  JOIN users                   ua  ON ua.id  = f.user_autoriza_id
            LEFT  JOIN users                   up  ON up.id  = f.user_aprueba_id
            WHERE f.deleted_at IS NULL
        SQL);

        // ─────────────────────────────────────────────────────────────────
        // 2. Dashboard agregado por sucursal
        //    Reemplaza stats()/stats2() (10 subconsultas → 1 GROUP BY)
        // ─────────────────────────────────────────────────────────────────
        DB::statement('DROP VIEW IF EXISTS v_fich_dashboard_sucursal');
        DB::statement(<<<'SQL'
            CREATE VIEW v_fich_dashboard_sucursal AS
            SELECT
                f.id_empresa,
                f.id_sucursal,
                f.sucursal_legacy,
                COUNT(*)                                                              AS total,
                SUM(e.codigo IN ('borrador','actualizacion_generada'))                AS borradores,
                SUM(e.codigo IN ('autorizada','actualizacion_autorizada'))            AS por_aprobar,
                SUM(e.codigo IN ('generada','actualizacion_en_proceso','por_aprobar')) AS en_proceso,
                SUM(e.codigo IN ('rechazada','actualizacion_rechazada'))              AS rechazadas,
                SUM(e.codigo IN ('finalizada','actualizacion_finalizada'))            AS finalizadas,
                SUM(e.codigo = 'cancelada')                                           AS canceladas,
                SUM(e.cuenta_vigencia = 1 AND f.fecha_fin >= CURDATE())               AS vigentes,
                SUM(e.cuenta_vigencia = 1 AND f.fecha_fin <  CURDATE())               AS vencidas,
                SUM(
                    e.cuenta_vigencia = 1
                    AND f.fecha_fin >= CURDATE()
                    AND f.fecha_fin <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                )                                                                     AS proximas_vencer,
                SUM(CASE WHEN e.cuenta_vigencia = 1 THEN f.vlr_contrato ELSE 0 END)   AS valor_contratado
            FROM fich_fichas f
            INNER JOIN fich_estados e ON e.id = f.id_estado
            WHERE f.deleted_at IS NULL
            GROUP BY f.id_empresa, f.id_sucursal, f.sucursal_legacy
        SQL);

        // ─────────────────────────────────────────────────────────────────
        // 3. Detalle enriquecido de servicios
        //    Reemplaza los JOIN duplicados de los 4 generadores de PDF
        // ─────────────────────────────────────────────────────────────────
        DB::statement('DROP VIEW IF EXISTS v_fich_detalles_completo');
        DB::statement(<<<'SQL'
            CREATE VIEW v_fich_detalles_completo AS
            SELECT
                d.id,
                d.id_ficha,
                d.tipo_liquidacion,
                d.tipo_servicio,
                d.id_tipo_servicio,
                ts.descripcion       AS tipo_servicio_descripcion,
                d.cups,
                c.desc_subcat        AS cups_descripcion,
                c.resolucion         AS cups_resolucion,
                d.grupo,
                cg.desc_grup         AS grupo_descripcion,
                d.subgrupo,
                cs.desc_subg         AS subgrupo_descripcion,
                d.forma_pago,
                d.homologo,
                h.tipo_manual        AS homologo_tipo_manual,
                h.desc_manual        AS homologo_descripcion,
                h.uvr_grupo          AS homologo_uvr_grupo,
                d.variacion,
                d.valor,
                d.id_obs_item,
                oi.descripcion       AS obs_item_descripcion,
                d.novedad,
                d.created_at,
                d.updated_at
            FROM fich_detalles d
            LEFT JOIN fich_tipos_servicio ts ON ts.id = d.id_tipo_servicio
            LEFT JOIN fich_obs_items      oi ON oi.id = d.id_obs_item
            LEFT JOIN fich_cups           c  ON c.subcategoria = d.cups      AND c.es_vigente = 1
            LEFT JOIN fich_cups           cg ON cg.grupo       = d.grupo     AND cg.es_vigente = 1 AND cg.subgrupo IS NULL
            LEFT JOIN fich_cups           cs ON cs.subgrupo    = d.subgrupo  AND cs.es_vigente = 1
            LEFT JOIN fich_homologos      h  ON h.code_manual  = d.homologo
        SQL);

        // ─────────────────────────────────────────────────────────────────
        // 4. Fichas próximas a vencer (alerta del dashboard)
        // ─────────────────────────────────────────────────────────────────
        DB::statement('DROP VIEW IF EXISTS v_fich_proximos_vencer');
        DB::statement(<<<'SQL'
            CREATE VIEW v_fich_proximos_vencer AS
            SELECT
                f.id,
                f.consecutivo,
                f.id_empresa,
                f.id_sucursal,
                f.sucursal_legacy,
                f.id_user_reg,
                f.fecha_fin,
                f.vlr_contrato,
                a.nombre       AS agremiacion_nombre,
                esp.descripcion AS especialidad_descripcion,
                DATEDIFF(f.fecha_fin, CURDATE()) AS dias_restantes,
                CASE
                    WHEN DATEDIFF(f.fecha_fin, CURDATE()) <= 10 THEN '#dc3545'
                    WHEN DATEDIFF(f.fecha_fin, CURDATE()) <= 15 THEN '#fd7e14'
                    ELSE '#ffc107'
                END AS color_alerta
            FROM fich_fichas f
            INNER JOIN fich_estados        e   ON e.id   = f.id_estado
            INNER JOIN fich_agremiaciones  a   ON a.id   = f.id_agremiacion
            INNER JOIN fich_especialidades esp ON esp.id = f.id_especialidad
            WHERE f.deleted_at IS NULL
              AND e.cuenta_vigencia = 1
              AND f.fecha_fin >= CURDATE()
              AND f.fecha_fin <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        SQL);

        // ─────────────────────────────────────────────────────────────────
        // 5. Profesionales con su especialidad (carga en cascada del form)
        // ─────────────────────────────────────────────────────────────────
        DB::statement('DROP VIEW IF EXISTS v_fich_profesionales_especialidad');
        DB::statement(<<<'SQL'
            CREATE VIEW v_fich_profesionales_especialidad AS
            SELECT
                pe.id            AS id_relacion,
                p.id             AS id_profesional,
                p.documento,
                p.nombre         AS profesional_nombre,
                p.tarjeta_profesional,
                p.correo         AS profesional_correo,
                p.estado         AS profesional_estado,
                esp.id           AS id_especialidad,
                esp.descripcion  AS especialidad_descripcion,
                esp.perfil       AS especialidad_perfil,
                esp.estado       AS especialidad_estado
            FROM fich_profesional_especialidad pe
            INNER JOIN fich_profesionales  p   ON p.id   = pe.id_profesional
            INNER JOIN fich_especialidades esp ON esp.id = pe.id_especialidad
        SQL);

        // ─────────────────────────────────────────────────────────────────
        // 6. Profesionales ocupados (soporte a la validación de conflictos)
        // ─────────────────────────────────────────────────────────────────
        DB::statement('DROP VIEW IF EXISTS v_fich_profesionales_ocupados');
        DB::statement(<<<'SQL'
            CREATE VIEW v_fich_profesionales_ocupados AS
            SELECT
                fp.id_profesional,
                p.nombre        AS profesional_nombre,
                p.documento     AS profesional_documento,
                f.id            AS id_ficha,
                f.consecutivo,
                f.fecha_ini,
                f.fecha_fin,
                f.id_sucursal,
                f.sucursal_legacy,
                e.codigo        AS estado_codigo
            FROM fich_ficha_profesional fp
            INNER JOIN fich_fichas       f ON f.id = fp.id_ficha
            INNER JOIN fich_profesionales p ON p.id = fp.id_profesional
            INNER JOIN fich_estados      e ON e.id = f.id_estado
            WHERE f.deleted_at IS NULL
              AND e.codigo IN ('finalizada', 'actualizacion_finalizada')
        SQL);
    }

    public function down(): void
    {
        foreach ([
            'v_fich_profesionales_ocupados',
            'v_fich_profesionales_especialidad',
            'v_fich_proximos_vencer',
            'v_fich_detalles_completo',
            'v_fich_dashboard_sucursal',
            'v_fich_fichas_listado',
        ] as $view) {
            DB::statement("DROP VIEW IF EXISTS {$view}");
        }
    }
};
