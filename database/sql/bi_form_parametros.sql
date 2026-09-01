-- Parámetros de campos por formulario BI (visibilidad, requerido, etiqueta)

CREATE TABLE IF NOT EXISTS `bi_form_parametros` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `formulario_codigo` varchar(80) NOT NULL,
  `campos` json NOT NULL,
  `usuario_actualiza_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bi_form_parametros_formulario_codigo_unique` (`formulario_codigo`),
  KEY `bi_form_parametros_usuario_actualiza_id_foreign` (`usuario_actualiza_id`),
  CONSTRAINT `bi_form_parametros_usuario_actualiza_id_foreign` FOREIGN KEY (`usuario_actualiza_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
