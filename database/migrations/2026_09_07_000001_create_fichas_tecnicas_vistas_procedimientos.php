<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Crea las vistas y procedimientos almacenados del módulo Fichas Técnicas.
 *
 * Estos objetos existían solo en la BD de desarrollo (creados manualmente) y
 * nunca se versionaron, por lo que producción no los tenía y el módulo fallaba
 * con "Server Error 500" al crear/listar fichas (p. ej. CALL
 * sp_fich_recalcular_totales sobre un SP inexistente).
 *
 * Objetos incluidos:
 *   Vistas: v_fich_dashboard_sucursal, v_fich_detalles_completo,
 *           v_fich_fichas_listado, v_fich_profesionales_especialidad,
 *           v_fich_profesionales_ocupados, v_fich_proximos_vencer
 *   SP:     sp_fich_recalcular_totales, sp_fich_siguiente_consecutivo,
 *           sp_fich_siguiente_version_os, sp_fich_conflictos_profesionales
 *
 * Sin prefijo de esquema ni DEFINER para que sea portable entre servidores.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Vistas ────────────────────────────────────────────────────────
        DB::unprepared('DROP VIEW IF EXISTS v_fich_dashboard_sucursal');
        DB::unprepared(<<<'SQL'
CREATE VIEW v_fich_dashboard_sucursal AS
select `f`.`id_empresa` AS `id_empresa`,`f`.`id_sucursal` AS `id_sucursal`,`f`.`sucursal_legacy` AS `sucursal_legacy`,count(0) AS `total`,sum(`e`.`codigo` in ('borrador','os_borrador')) AS `borradores`,sum(`e`.`codigo` in ('pendiente_autorizacion','os_pendiente_autorizacion')) AS `pendientes_autorizacion`,sum(`e`.`codigo` in ('pendiente_revision_financiera','os_pendiente_revision_financiera')) AS `pendientes_financiera`,sum(`e`.`codigo` in ('pendiente_autorizacion','os_pendiente_autorizacion','pendiente_revision_financiera','os_pendiente_revision_financiera')) AS `en_proceso`,sum(`e`.`codigo` in ('pendiente_revision_financiera','os_pendiente_revision_financiera')) AS `por_aprobar`,sum(`e`.`codigo` in ('correccion_requerida','os_correccion_requerida')) AS `rechazadas`,sum(`e`.`codigo` in ('aprobada','os_aprobada')) AS `aprobadas`,sum(`e`.`codigo` in ('vigente','os_vigente')) AS `en_vigencia`,sum(`e`.`codigo` in ('aprobada','os_aprobada','vigente','os_vigente')) AS `finalizadas`,sum(`e`.`codigo` in ('cancelada','os_cancelada')) AS `canceladas`,sum(`e`.`cuenta_vigencia` = 1 and `f`.`fecha_fin` >= curdate()) AS `vigentes`,sum(`e`.`cuenta_vigencia` = 1 and `f`.`fecha_fin` < curdate()) AS `vencidas`,sum(`e`.`cuenta_vigencia` = 1 and `f`.`fecha_fin` >= curdate() and `f`.`fecha_fin` <= curdate() + interval 30 day) AS `proximas_vencer`,sum(case when `e`.`cuenta_vigencia` = 1 then `f`.`vlr_contrato` else 0 end) AS `valor_contratado` from (`fich_fichas` `f` join `fich_estados` `e` on(`e`.`id` = `f`.`id_estado`)) where `f`.`deleted_at` is null group by `f`.`id_empresa`,`f`.`id_sucursal`,`f`.`sucursal_legacy`
SQL);

        DB::unprepared('DROP VIEW IF EXISTS v_fich_detalles_completo');
        DB::unprepared(<<<'SQL'
CREATE VIEW v_fich_detalles_completo AS
select `d`.`id` AS `id`,`d`.`id_ficha` AS `id_ficha`,`d`.`tipo_liquidacion` AS `tipo_liquidacion`,`d`.`tipo_servicio` AS `tipo_servicio`,`d`.`id_tipo_servicio` AS `id_tipo_servicio`,`ts`.`descripcion` AS `tipo_servicio_descripcion`,`d`.`cups` AS `cups`,`c`.`desc_subcat` AS `cups_descripcion`,`c`.`resolucion` AS `cups_resolucion`,`d`.`grupo` AS `grupo`,`cg`.`desc_grup` AS `grupo_descripcion`,`d`.`subgrupo` AS `subgrupo`,`cs`.`desc_subg` AS `subgrupo_descripcion`,`d`.`forma_pago` AS `forma_pago`,`d`.`homologo` AS `homologo`,`h`.`tipo_manual` AS `homologo_tipo_manual`,`h`.`desc_manual` AS `homologo_descripcion`,`h`.`uvr_grupo` AS `homologo_uvr_grupo`,`d`.`variacion` AS `variacion`,`d`.`valor` AS `valor`,`d`.`id_obs_item` AS `id_obs_item`,`oi`.`descripcion` AS `obs_item_descripcion`,`d`.`novedad` AS `novedad`,`d`.`created_at` AS `created_at`,`d`.`updated_at` AS `updated_at` from ((((((`fich_detalles` `d` left join `fich_tipos_servicio` `ts` on(`ts`.`id` = `d`.`id_tipo_servicio`)) left join `fich_obs_items` `oi` on(`oi`.`id` = `d`.`id_obs_item`)) left join `fich_cups` `c` on(`c`.`subcategoria` = `d`.`cups` and `c`.`es_vigente` = 1)) left join `fich_cups` `cg` on(`cg`.`grupo` = `d`.`grupo` and `cg`.`es_vigente` = 1 and `cg`.`subgrupo` is null)) left join `fich_cups` `cs` on(`cs`.`subgrupo` = `d`.`subgrupo` and `cs`.`es_vigente` = 1)) left join `fich_homologos` `h` on(`h`.`code_manual` = `d`.`homologo`))
SQL);

        DB::unprepared('DROP VIEW IF EXISTS v_fich_fichas_listado');
        DB::unprepared(<<<'SQL'
CREATE VIEW v_fich_fichas_listado AS
select `f`.`id` AS `id`,`f`.`consecutivo` AS `consecutivo`,`f`.`id_padre` AS `id_padre`,`f`.`version` AS `version`,`f`.`vlr_contrato` AS `vlr_contrato`,`f`.`fecha_ini` AS `fecha_ini`,`f`.`fecha_fin` AS `fecha_fin`,`f`.`fecha_reg` AS `fecha_reg`,`f`.`obs_os` AS `obs_os`,`f`.`novedad` AS `novedad`,`f`.`total_detalles` AS `total_detalles`,`f`.`valor_total_detalles` AS `valor_total_detalles`,`f`.`total_profesionales` AS `total_profesionales`,`f`.`created_at` AS `created_at`,`f`.`updated_at` AS `updated_at`,`f`.`id_estado` AS `id_estado`,`e`.`codigo` AS `estado_codigo`,`e`.`descripcion` AS `estado_descripcion`,`e`.`tipo` AS `estado_tipo`,`e`.`color_hex` AS `estado_color`,`e`.`es_editable` AS `estado_es_editable`,`e`.`es_final` AS `estado_es_final`,`f`.`id_agremiacion` AS `id_agremiacion`,`a`.`nombre` AS `agremiacion_nombre`,`a`.`nit` AS `agremiacion_nit`,`f`.`id_especialidad` AS `id_especialidad`,`esp`.`descripcion` AS `especialidad_descripcion`,`esp`.`perfil` AS `especialidad_perfil`,`f`.`id_objeto_contrato` AS `id_objeto_contrato`,`obj`.`descripcion` AS `objeto_contrato_descripcion`,`f`.`id_empresa` AS `id_empresa`,`emp`.`nombre` AS `empresa_nombre`,`emp`.`prefijo` AS `empresa_prefijo`,`f`.`id_sucursal` AS `id_sucursal`,`suc`.`nombre` AS `sucursal_nombre`,`f`.`sucursal_legacy` AS `sucursal_legacy`,`f`.`id_user_reg` AS `id_user_reg`,`ug`.`name` AS `generador_nombre`,`ug`.`email` AS `generador_email`,`f`.`user_autoriza_id` AS `user_autoriza_id`,`f`.`fecha_autoriza` AS `fecha_autoriza`,`f`.`obs_autoriza` AS `obs_autoriza`,`ua`.`name` AS `autorizador_nombre`,`ua`.`email` AS `autorizador_email`,`f`.`user_aprueba_id` AS `user_aprueba_id`,`f`.`fecha_aprueba` AS `fecha_aprueba`,`f`.`obs_aprueba` AS `obs_aprueba`,`up`.`name` AS `aprobador_nombre`,`up`.`email` AS `aprobador_email`,to_days(`f`.`fecha_fin`) - to_days(curdate()) AS `dias_restantes`,case when `f`.`fecha_fin` < curdate() then 'VENCIDA' when to_days(`f`.`fecha_fin`) - to_days(curdate()) <= 10 then 'CRITICA' when to_days(`f`.`fecha_fin`) - to_days(curdate()) <= 15 then 'ALERTA' when to_days(`f`.`fecha_fin`) - to_days(curdate()) <= 30 then 'PROXIMA' else 'VIGENTE' end AS `vigencia_estado` from (((((((((`fich_fichas` `f` join `fich_estados` `e` on(`e`.`id` = `f`.`id_estado`)) join `fich_agremiaciones` `a` on(`a`.`id` = `f`.`id_agremiacion`)) join `fich_especialidades` `esp` on(`esp`.`id` = `f`.`id_especialidad`)) join `fich_objetos_contrato` `obj` on(`obj`.`id` = `f`.`id_objeto_contrato`)) join `users` `ug` on(`ug`.`id` = `f`.`id_user_reg`)) left join `ent_empresas` `emp` on(`emp`.`id` = `f`.`id_empresa`)) left join `config_ubi_sucursales` `suc` on(`suc`.`id` = `f`.`id_sucursal`)) left join `users` `ua` on(`ua`.`id` = `f`.`user_autoriza_id`)) left join `users` `up` on(`up`.`id` = `f`.`user_aprueba_id`)) where `f`.`deleted_at` is null
SQL);

        DB::unprepared('DROP VIEW IF EXISTS v_fich_profesionales_especialidad');
        DB::unprepared(<<<'SQL'
CREATE VIEW v_fich_profesionales_especialidad AS
select `pe`.`id` AS `id_relacion`,`p`.`id` AS `id_profesional`,`p`.`documento` AS `documento`,`p`.`nombre` AS `profesional_nombre`,`p`.`tarjeta_profesional` AS `tarjeta_profesional`,`p`.`correo` AS `profesional_correo`,`p`.`estado` AS `profesional_estado`,`esp`.`id` AS `id_especialidad`,`esp`.`descripcion` AS `especialidad_descripcion`,`esp`.`perfil` AS `especialidad_perfil`,`esp`.`estado` AS `especialidad_estado` from ((`fich_profesional_especialidad` `pe` join `fich_profesionales` `p` on(`p`.`id` = `pe`.`id_profesional`)) join `fich_especialidades` `esp` on(`esp`.`id` = `pe`.`id_especialidad`))
SQL);

        DB::unprepared('DROP VIEW IF EXISTS v_fich_profesionales_ocupados');
        DB::unprepared(<<<'SQL'
CREATE VIEW v_fich_profesionales_ocupados AS
select `fp`.`id_profesional` AS `id_profesional`,`p`.`nombre` AS `profesional_nombre`,`p`.`documento` AS `profesional_documento`,`f`.`id` AS `id_ficha`,`f`.`consecutivo` AS `consecutivo`,`f`.`fecha_ini` AS `fecha_ini`,`f`.`fecha_fin` AS `fecha_fin`,`f`.`id_sucursal` AS `id_sucursal`,`f`.`sucursal_legacy` AS `sucursal_legacy`,`f`.`id_empresa` AS `id_empresa`,`f`.`id_agremiacion` AS `id_agremiacion`,`a`.`nombre` AS `agremiacion_nombre`,`f`.`id_especialidad` AS `id_especialidad`,`esp`.`descripcion` AS `especialidad_descripcion`,`e`.`codigo` AS `estado_codigo`,`e`.`descripcion` AS `estado_descripcion` from (((((`fich_ficha_profesional` `fp` join `fich_fichas` `f` on(`f`.`id` = `fp`.`id_ficha`)) join `fich_profesionales` `p` on(`p`.`id` = `fp`.`id_profesional`)) join `fich_estados` `e` on(`e`.`id` = `f`.`id_estado`)) join `fich_agremiaciones` `a` on(`a`.`id` = `f`.`id_agremiacion`)) join `fich_especialidades` `esp` on(`esp`.`id` = `f`.`id_especialidad`)) where `f`.`deleted_at` is null and `e`.`codigo` in ('aprobada','vigente','os_aprobada','os_vigente')
SQL);

        DB::unprepared('DROP VIEW IF EXISTS v_fich_proximos_vencer');
        DB::unprepared(<<<'SQL'
CREATE VIEW v_fich_proximos_vencer AS
select `f`.`id` AS `id`,`f`.`consecutivo` AS `consecutivo`,`f`.`id_empresa` AS `id_empresa`,`f`.`id_sucursal` AS `id_sucursal`,`f`.`sucursal_legacy` AS `sucursal_legacy`,`f`.`id_user_reg` AS `id_user_reg`,`f`.`fecha_fin` AS `fecha_fin`,`f`.`vlr_contrato` AS `vlr_contrato`,`a`.`nombre` AS `agremiacion_nombre`,`esp`.`descripcion` AS `especialidad_descripcion`,`e`.`codigo` AS `estado_codigo`,to_days(`f`.`fecha_fin`) - to_days(curdate()) AS `dias_restantes`,case when to_days(`f`.`fecha_fin`) - to_days(curdate()) <= 10 then '#dc3545' when to_days(`f`.`fecha_fin`) - to_days(curdate()) <= 15 then '#fd7e14' else '#ffc107' end AS `color_alerta` from (((`fich_fichas` `f` join `fich_estados` `e` on(`e`.`id` = `f`.`id_estado`)) join `fich_agremiaciones` `a` on(`a`.`id` = `f`.`id_agremiacion`)) join `fich_especialidades` `esp` on(`esp`.`id` = `f`.`id_especialidad`)) where `f`.`deleted_at` is null and `e`.`cuenta_vigencia` = 1 and `f`.`fecha_fin` >= curdate() and `f`.`fecha_fin` <= curdate() + interval 30 day
SQL);

        // ── Procedimientos almacenados ─────────────────────────────────────
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_fich_recalcular_totales');
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

        DB::unprepared('DROP PROCEDURE IF EXISTS sp_fich_siguiente_consecutivo');
        DB::unprepared(<<<'SQL'
CREATE PROCEDURE sp_fich_siguiente_consecutivo(
    IN  p_prefijo     VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN  p_anio        SMALLINT,
    OUT p_consecutivo VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
)
BEGIN
    DECLARE v_base       VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
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

        DB::unprepared('DROP PROCEDURE IF EXISTS sp_fich_siguiente_version_os');
        DB::unprepared(<<<'SQL'
CREATE PROCEDURE sp_fich_siguiente_version_os(
    IN  p_id_padre    BIGINT,
    OUT p_consecutivo VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    OUT p_version     SMALLINT
)
BEGIN
    DECLARE v_consecutivo_padre VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
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

        DB::unprepared('DROP PROCEDURE IF EXISTS sp_fich_conflictos_profesionales');
        DB::unprepared(<<<'SQL'
CREATE PROCEDURE sp_fich_conflictos_profesionales(
    IN p_ids_profesionales TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_fich_conflictos_profesionales');
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_fich_siguiente_version_os');
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_fich_siguiente_consecutivo');
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_fich_recalcular_totales');

        DB::unprepared('DROP VIEW IF EXISTS v_fich_proximos_vencer');
        DB::unprepared('DROP VIEW IF EXISTS v_fich_profesionales_ocupados');
        DB::unprepared('DROP VIEW IF EXISTS v_fich_profesionales_especialidad');
        DB::unprepared('DROP VIEW IF EXISTS v_fich_fichas_listado');
        DB::unprepared('DROP VIEW IF EXISTS v_fich_detalles_completo');
        DB::unprepared('DROP VIEW IF EXISTS v_fich_dashboard_sucursal');
    }
};
