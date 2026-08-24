-- Hoja de traslado asistencial (primario / secundario)
-- Estados: guardado | confirmado

CREATE TABLE IF NOT EXISTS `bi_from_tras_asistencial` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tipo` enum('primario','secundario') NOT NULL COMMENT 'Diferencia hoja primaria o secundaria',
  `formato` varchar(30) NOT NULL DEFAULT 'primario' COMMENT 'Variante UI: primario, primarioCompleto, secundario, secundarioCompleto',
  `estado` enum('guardado','confirmado') NOT NULL DEFAULT 'guardado',
  `fecha_guarda` datetime NOT NULL,
  `usuario_guarda_id` bigint(20) UNSIGNED NOT NULL,
  `fecha_confirma` datetime DEFAULT NULL,
  `usuario_confirma_id` bigint(20) UNSIGNED DEFAULT NULL,
  `fecha_atencion` date DEFAULT NULL,
  `nombres_apellidos` varchar(255) DEFAULT NULL,
  `tipo_identificacion` varchar(20) DEFAULT NULL,
  `numero_identificacion` varchar(30) DEFAULT NULL,
  `estado_paciente` varchar(10) DEFAULT NULL,
  `datos` json NOT NULL COMMENT 'Payload completo del formulario',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bi_from_tras_asistencial_tipo_estado_index` (`tipo`,`estado`),
  KEY `bi_from_tras_asistencial_numero_identificacion_index` (`numero_identificacion`),
  KEY `bi_from_tras_asistencial_fecha_guarda_index` (`fecha_guarda`),
  KEY `bi_from_tras_asistencial_usuario_guarda_id_foreign` (`usuario_guarda_id`),
  KEY `bi_from_tras_asistencial_usuario_confirma_id_foreign` (`usuario_confirma_id`),
  CONSTRAINT `bi_from_tras_asistencial_usuario_guarda_id_foreign` FOREIGN KEY (`usuario_guarda_id`) REFERENCES `users` (`id`),
  CONSTRAINT `bi_from_tras_asistencial_usuario_confirma_id_foreign` FOREIGN KEY (`usuario_confirma_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
