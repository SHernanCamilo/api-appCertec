<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 1 — Alinea vistas y procedimientos con el nuevo workflow.
 *
 * Cambios:
 *  1. `v_fich_dashboard_sucursal` usa los códigos de estado nuevos y desglosa
 *     `pendientes_autorizacion` / `pendientes_financiera`, que antes se
 *     agrupaban indistintamente en "en_proceso".
 *  2. `v_fich_profesionales_ocupados` expone la agremiación de la ficha
 *     vigente. Es lo que permite distinguir RN-01 (alerta: misma agremiación)
 *     de RN-02 (bloqueo: agremiación diferente), algo imposible antes porque
 *     la vista no traía ese dato.
 *  3. `sp_fich_conflictos_profesionales` devuelve la agremiación y clasifica el
 *     conflicto en `tipo_conflicto` = ALERTA | BLOQUEO, recibiendo la
 *     agremiación de la ficha que se está creando.
 */
return new class extends Migration
{
    public function up(): void
    {
        $collation = (string) config('database.connections.mysql.collation', 'utf8mb4_unicode_ci');
        $charset   = (string) config('database.connections.mysql.charset', 'utf8mb4');
        $txt       = "CHARACTER SET {$charset} COLLATE {$collation}";

        $this->vistaDashboard();
        $this->vistaProfesionalesOcupados();
        $this->vistaProximosVencer();
        $this->procedimientoConflictos($txt);
    }

    private function vistaDashboard(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_fich_dashboard_sucursal');
        DB::statement(<<<'SQL'
            CREATE VIEW v_fich_dashboard_sucursal AS
            SELECT
                f.id_empresa,
                f.id_sucursal,
                f.sucursal_legacy,
                COUNT(*) AS total,
                SUM(e.codigo IN ('borrador','os_borrador'))                                              AS borradores,
                SUM(e.codigo IN ('pendiente_autorizacion','os_pendiente_autorizacion'))                  AS pendientes_autorizacion,
                SUM(e.codigo IN ('pendiente_revision_financiera','os_pendiente_revision_financiera'))    AS pendientes_financiera,
                SUM(e.codigo IN ('pendiente_autorizacion','os_pendiente_autorizacion',
                                 'pendiente_revision_financiera','os_pendiente_revision_financiera'))    AS en_proceso,
                SUM(e.codigo IN ('pendiente_revision_financiera','os_pendiente_revision_financiera'))    AS por_aprobar,
                SUM(e.codigo IN ('correccion_requerida','os_correccion_requerida'))                      AS rechazadas,
                SUM(e.codigo IN ('aprobada','os_aprobada'))                                              AS aprobadas,
                SUM(e.codigo IN ('vigente','os_vigente'))                                                AS en_vigencia,
                SUM(e.codigo IN ('aprobada','os_aprobada','vigente','os_vigente'))                       AS finalizadas,
                SUM(e.codigo IN ('cancelada','os_cancelada'))                                            AS canceladas,
                SUM(e.cuenta_vigencia = 1 AND f.fecha_fin >= CURDATE())                                  AS vigentes,
                SUM(e.cuenta_vigencia = 1 AND f.fecha_fin <  CURDATE())                                  AS vencidas,
                SUM(
                    e.cuenta_vigencia = 1
                    AND f.fecha_fin >= CURDATE()
                    AND f.fecha_fin <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                )                                                                                        AS proximas_vencer,
                SUM(CASE WHEN e.cuenta_vigencia = 1 THEN f.vlr_contrato ELSE 0 END)                      AS valor_contratado
            FROM fich_fichas f
            INNER JOIN fich_estados e ON e.id = f.id_estado
            WHERE f.deleted_at IS NULL
            GROUP BY f.id_empresa, f.id_sucursal, f.sucursal_legacy
        SQL);
    }

    /**
     * Profesionales comprometidos en fichas con vigencia contractual.
     *
     * Se consideran ocupados los profesionales de fichas `aprobada` y `vigente`
     * (y sus equivalentes OS): una ficha aprobada ya es un compromiso, aunque
     * su vigencia arranque más adelante.
     */
    private function vistaProfesionalesOcupados(): void
    {
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
                f.id_empresa,
                f.id_agremiacion,
                a.nombre        AS agremiacion_nombre,
                f.id_especialidad,
                esp.descripcion AS especialidad_descripcion,
                e.codigo        AS estado_codigo,
                e.descripcion   AS estado_descripcion
            FROM fich_ficha_profesional fp
            INNER JOIN fich_fichas         f   ON f.id   = fp.id_ficha
            INNER JOIN fich_profesionales  p   ON p.id   = fp.id_profesional
            INNER JOIN fich_estados        e   ON e.id   = f.id_estado
            INNER JOIN fich_agremiaciones  a   ON a.id   = f.id_agremiacion
            INNER JOIN fich_especialidades esp ON esp.id = f.id_especialidad
            WHERE f.deleted_at IS NULL
              AND e.codigo IN ('aprobada', 'vigente', 'os_aprobada', 'os_vigente')
        SQL);
    }

    private function vistaProximosVencer(): void
    {
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
                a.nombre        AS agremiacion_nombre,
                esp.descripcion AS especialidad_descripcion,
                e.codigo        AS estado_codigo,
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
    }

    /**
     * Conflictos de profesionales, clasificados por agremiación.
     *
     * `p_id_agremiacion` es la agremiación de la ficha que se está creando o
     * editando. Con ella el procedimiento clasifica cada solapamiento:
     *
     *   ALERTA  → la ficha vigente es de la MISMA agremiación (RN-01, informa)
     *   BLOQUEO → la ficha vigente es de OTRA agremiación   (RN-02, impide)
     *
     * Si se pasa NULL, todo se marca como ALERTA: es el comportamiento para
     * consultas exploratorias en las que aún no se eligió agremiación.
     */
    private function procedimientoConflictos(string $txt): void
    {
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_fich_conflictos_profesionales');
        DB::unprepared(<<<SQL
            CREATE PROCEDURE sp_fich_conflictos_profesionales(
                IN p_ids_profesionales TEXT {$txt},
                IN p_fecha_ini         DATE,
                IN p_fecha_fin         DATE,
                IN p_excluir_ficha     BIGINT,
                IN p_id_agremiacion    BIGINT
            )
            BEGIN
                SELECT
                    po.id_profesional,
                    po.profesional_nombre,
                    po.profesional_documento,
                    po.id_ficha,
                    IFNULL(po.consecutivo, 'SIN-CONSECUTIVO') AS consecutivo,
                    po.fecha_ini,
                    po.fecha_fin,
                    po.sucursal_legacy,
                    po.id_sucursal,
                    po.id_agremiacion,
                    po.agremiacion_nombre,
                    po.id_especialidad,
                    po.especialidad_descripcion,
                    po.estado_codigo,
                    po.estado_descripcion,
                    CASE
                        WHEN p_id_agremiacion IS NULL              THEN 'ALERTA'
                        WHEN po.id_agremiacion = p_id_agremiacion  THEN 'ALERTA'
                        ELSE 'BLOQUEO'
                    END AS tipo_conflicto
                FROM v_fich_profesionales_ocupados po
                WHERE FIND_IN_SET(po.id_profesional, p_ids_profesionales) > 0
                  AND po.fecha_ini <= p_fecha_fin
                  AND po.fecha_fin >= p_fecha_ini
                  AND (p_excluir_ficha IS NULL OR po.id_ficha <> p_excluir_ficha)
                ORDER BY tipo_conflicto DESC, po.profesional_nombre, po.fecha_ini;
            END
        SQL);
    }

    public function down(): void
    {
        // Se restauran las definiciones previas desde las migraciones de origen.
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_fich_conflictos_profesionales');

        foreach ([
            'v_fich_proximos_vencer',
            'v_fich_profesionales_ocupados',
            'v_fich_dashboard_sucursal',
        ] as $vista) {
            DB::statement("DROP VIEW IF EXISTS {$vista}");
        }
    }
};
