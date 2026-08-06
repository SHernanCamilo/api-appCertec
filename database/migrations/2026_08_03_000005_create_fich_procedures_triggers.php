<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 1 — Procedimientos almacenados y triggers del módulo Fichas Técnicas.
 *
 * Procedimientos
 * ──────────────
 *  sp_fich_conflictos_profesionales : centraliza la regla crítica de negocio
 *      del legacy (`generador/acciones/insertar.php`), donde el SQL de
 *      solapamiento de fechas estaba escrito a mano con placeholders
 *      dinámicos y tres condiciones OR redundantes. Aquí se resuelve con la
 *      comparación canónica de intervalos (ini <= fin_nuevo AND fin >= ini_nuevo).
 *
 *  sp_fich_siguiente_consecutivo : el legacy contaba filas con
 *      `consecutivo LIKE '%PREFIJO-AÑO%'` y sumaba 1, lo que genera huecos y
 *      colisiones. Aquí se extrae el máximo sufijo numérico real.
 *
 *  sp_fich_siguiente_version_os : versión de actualización de una ficha padre.
 *
 * Triggers
 * ────────
 *  Mantienen los contadores denormalizados y la bitácora de estados, tareas
 *  que en el legacy quedaban a cargo del PHP (y frecuentemente se omitían).
 *  El usuario responsable se toma de la variable de sesión
 *  `@fich_usuario_actual`, que el servicio de aplicación fija antes de escribir.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropAll();

        // Las tablas se crean con la colación de la conexión de Laravel, pero las
        // variables/parámetros de una rutina almacenada heredan la colación por
        // defecto del esquema. Si difieren, MySQL lanza el error 1267 "Illegal mix
        // of collations" al comparar (LIKE / FIND_IN_SET). Se fija explícitamente
        // la colación de la conexión en cada parámetro y variable de texto.
        $collation = (string) config('database.connections.mysql.collation', 'utf8mb4_unicode_ci');
        $charset   = (string) config('database.connections.mysql.charset', 'utf8mb4');
        $txt       = "CHARACTER SET {$charset} COLLATE {$collation}";

        // ─────────────────────────────────────────────────────────────────
        // SP 1 — Conflictos de profesionales por solapamiento de vigencias
        // ─────────────────────────────────────────────────────────────────
        DB::unprepared(<<<SQL
            CREATE PROCEDURE sp_fich_conflictos_profesionales(
                IN p_ids_profesionales TEXT {$txt},
                IN p_fecha_ini         DATE,
                IN p_fecha_fin         DATE,
                IN p_excluir_ficha     BIGINT
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
                    po.estado_codigo
                FROM v_fich_profesionales_ocupados po
                WHERE FIND_IN_SET(po.id_profesional, p_ids_profesionales) > 0
                  AND po.fecha_ini <= p_fecha_fin
                  AND po.fecha_fin >= p_fecha_ini
                  AND (p_excluir_ficha IS NULL OR po.id_ficha <> p_excluir_ficha)
                ORDER BY po.profesional_nombre, po.fecha_ini;
            END
        SQL);

        // ─────────────────────────────────────────────────────────────────
        // SP 2 — Siguiente consecutivo de ficha nueva
        // ─────────────────────────────────────────────────────────────────
        DB::unprepared(<<<SQL
            CREATE PROCEDURE sp_fich_siguiente_consecutivo(
                IN  p_prefijo     VARCHAR(10) {$txt},
                IN  p_anio        SMALLINT,
                OUT p_consecutivo VARCHAR(60) {$txt}
            )
            BEGIN
                DECLARE v_base       VARCHAR(30) {$txt};
                DECLARE v_max_numero INT DEFAULT 0;

                SET v_base = CONCAT(p_prefijo, '-', p_anio, '-');

                SELECT IFNULL(
                           MAX(
                               CAST(
                                   SUBSTRING_INDEX(SUBSTRING(consecutivo, CHAR_LENGTH(v_base) + 1), '-', 1)
                                   AS UNSIGNED
                               )
                           ), 0)
                  INTO v_max_numero
                  FROM fich_fichas
                 WHERE deleted_at IS NULL
                   AND consecutivo LIKE CONCAT(v_base, '%');

                SET p_consecutivo = CONCAT(v_base, v_max_numero + 1);
            END
        SQL);

        // ─────────────────────────────────────────────────────────────────
        // SP 3 — Siguiente versión de una actualización (OS)
        // ─────────────────────────────────────────────────────────────────
        DB::unprepared(<<<SQL
            CREATE PROCEDURE sp_fich_siguiente_version_os(
                IN  p_id_padre    BIGINT,
                OUT p_consecutivo VARCHAR(60) {$txt},
                OUT p_version     SMALLINT
            )
            BEGIN
                DECLARE v_consecutivo_padre VARCHAR(60) {$txt};
                DECLARE v_total_versiones   INT DEFAULT 0;

                SELECT consecutivo INTO v_consecutivo_padre
                  FROM fich_fichas
                 WHERE id = p_id_padre
                 LIMIT 1;

                SELECT COUNT(*) INTO v_total_versiones
                  FROM fich_fichas
                 WHERE id_padre = p_id_padre
                   AND deleted_at IS NULL;

                SET p_version     = v_total_versiones + 1;
                SET p_consecutivo = CONCAT(IFNULL(v_consecutivo_padre, 'SIN-PADRE'), '-', p_version);
            END
        SQL);

        // ─────────────────────────────────────────────────────────────────
        // SP 4 — Recalcular totales de una ficha (utilitario de reparación)
        // ─────────────────────────────────────────────────────────────────
        DB::unprepared(<<<'SQL'
            CREATE PROCEDURE sp_fich_recalcular_totales(IN p_id_ficha BIGINT)
            BEGIN
                UPDATE fich_fichas f
                   SET f.total_detalles = (
                           SELECT COUNT(*) FROM fich_detalles d WHERE d.id_ficha = f.id
                       ),
                       f.valor_total_detalles = (
                           SELECT IFNULL(SUM(d.valor), 0) FROM fich_detalles d WHERE d.id_ficha = f.id
                       ),
                       f.total_profesionales = (
                           SELECT COUNT(*) FROM fich_ficha_profesional fp WHERE fp.id_ficha = f.id
                       )
                 WHERE f.id = p_id_ficha;
            END
        SQL);

        // ─────────────────────────────────────────────────────────────────
        // Triggers — contadores de detalles
        // ─────────────────────────────────────────────────────────────────
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_fich_detalles_ai AFTER INSERT ON fich_detalles
            FOR EACH ROW
            BEGIN
                UPDATE fich_fichas
                   SET total_detalles       = total_detalles + 1,
                       valor_total_detalles = valor_total_detalles + IFNULL(NEW.valor, 0)
                 WHERE id = NEW.id_ficha;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_fich_detalles_au AFTER UPDATE ON fich_detalles
            FOR EACH ROW
            BEGIN
                IF NEW.id_ficha = OLD.id_ficha THEN
                    UPDATE fich_fichas
                       SET valor_total_detalles = valor_total_detalles
                                                  - IFNULL(OLD.valor, 0)
                                                  + IFNULL(NEW.valor, 0)
                     WHERE id = NEW.id_ficha;
                ELSE
                    UPDATE fich_fichas
                       SET total_detalles       = total_detalles - 1,
                           valor_total_detalles = valor_total_detalles - IFNULL(OLD.valor, 0)
                     WHERE id = OLD.id_ficha;
                    UPDATE fich_fichas
                       SET total_detalles       = total_detalles + 1,
                           valor_total_detalles = valor_total_detalles + IFNULL(NEW.valor, 0)
                     WHERE id = NEW.id_ficha;
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_fich_detalles_ad AFTER DELETE ON fich_detalles
            FOR EACH ROW
            BEGIN
                UPDATE fich_fichas
                   SET total_detalles       = GREATEST(total_detalles - 1, 0),
                       valor_total_detalles = GREATEST(valor_total_detalles - IFNULL(OLD.valor, 0), 0)
                 WHERE id = OLD.id_ficha;
            END
        SQL);

        // ─────────────────────────────────────────────────────────────────
        // Triggers — contador de profesionales
        // ─────────────────────────────────────────────────────────────────
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_fich_ficha_prof_ai AFTER INSERT ON fich_ficha_profesional
            FOR EACH ROW
            BEGIN
                UPDATE fich_fichas
                   SET total_profesionales = total_profesionales + 1
                 WHERE id = NEW.id_ficha;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_fich_ficha_prof_ad AFTER DELETE ON fich_ficha_profesional
            FOR EACH ROW
            BEGIN
                UPDATE fich_fichas
                   SET total_profesionales = GREATEST(total_profesionales - 1, 0)
                 WHERE id = OLD.id_ficha;
            END
        SQL);

        // ─────────────────────────────────────────────────────────────────
        // Triggers — ficha: normalización y bitácora de estados
        // ─────────────────────────────────────────────────────────────────
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_fich_fichas_bi BEFORE INSERT ON fich_fichas
            FOR EACH ROW
            BEGIN
                IF NEW.fecha_reg IS NULL THEN
                    SET NEW.fecha_reg = NOW();
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_fich_fichas_ai AFTER INSERT ON fich_fichas
            FOR EACH ROW
            BEGIN
                INSERT INTO fich_historial_estados
                    (id_ficha, id_estado_anterior, id_estado_nuevo, id_usuario, observacion, created_at)
                VALUES
                    (NEW.id, NULL, NEW.id_estado, NEW.id_user_reg, 'Creación de la ficha', NOW());
            END
        SQL);

        DB::unprepared(<<<SQL
            CREATE TRIGGER trg_fich_fichas_au AFTER UPDATE ON fich_fichas
            FOR EACH ROW
            BEGIN
                DECLARE v_observacion TEXT {$txt};

                IF NOT (NEW.id_estado <=> OLD.id_estado) THEN
                    SET v_observacion = NULLIF(TRIM(IFNULL(CONVERT(@fich_observacion_actual USING {$charset}), '')), '');

                    INSERT INTO fich_historial_estados
                        (id_ficha, id_estado_anterior, id_estado_nuevo, id_usuario, observacion, created_at)
                    VALUES (
                        NEW.id,
                        OLD.id_estado,
                        NEW.id_estado,
                        NULLIF(CAST(IFNULL(@fich_usuario_actual, 0) AS UNSIGNED), 0),
                        IFNULL(
                            v_observacion,
                            CONCAT('Cambio de estado ', OLD.id_estado, ' -> ', NEW.id_estado)
                        ),
                        NOW()
                    );
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        $this->dropAll();
    }

    private function dropAll(): void
    {
        foreach ([
            'trg_fich_detalles_ai',
            'trg_fich_detalles_au',
            'trg_fich_detalles_ad',
            'trg_fich_ficha_prof_ai',
            'trg_fich_ficha_prof_ad',
            'trg_fich_fichas_bi',
            'trg_fich_fichas_ai',
            'trg_fich_fichas_au',
        ] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }

        foreach ([
            'sp_fich_conflictos_profesionales',
            'sp_fich_siguiente_consecutivo',
            'sp_fich_siguiente_version_os',
            'sp_fich_recalcular_totales',
        ] as $procedure) {
            DB::unprepared("DROP PROCEDURE IF EXISTS {$procedure}");
        }
    }
};
