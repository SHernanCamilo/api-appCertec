-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: digipharma
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `compras`
--

DROP TABLE IF EXISTS `compras`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `compras` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `numero_orden_compra` varchar(100) NOT NULL COMMENT 'N??mero de Orden de Compra INVIMA VIE (puede ser parametrizado)',
  `fecha_orden` date NOT NULL COMMENT 'Fecha de la orden de compra',
  `observaciones` text DEFAULT NULL COMMENT 'Observaciones generales de la compra',
  `estado` enum('pendiente','en_transito','confirmado','recibida','cancelada','en_sitio') DEFAULT 'pendiente',
  `sincronizado_indigo` int(11) NOT NULL,
  `creado_por` int(11) NOT NULL COMMENT 'Usuario que cre?? la compra',
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `oc_indigo` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero_orden_compra` (`numero_orden_compra`),
  KEY `creado_por` (`creado_por`),
  KEY `idx_compras_fecha_orden` (`fecha_orden`),
  KEY `idx_compras_estado` (`estado`),
  KEY `idx_compras_numero_orden` (`numero_orden_compra`),
  KEY `idx_compras_estado_fecha` (`estado`,`fecha_orden`),
  KEY `idx_compras_oc_indigo` (`oc_indigo`),
  KEY `idx_compras_creado_por` (`creado_por`),
  KEY `idx_compras_fecha` (`fecha_orden`),
  KEY `idx_compras_numero` (`numero_orden_compra`),
  CONSTRAINT `compras_ibfk_1` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cabecera de ??rdenes de compra - Puede incluir uno o m??ltiples pedidos';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `compras_detalle`
--

DROP TABLE IF EXISTS `compras_detalle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `compras_detalle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `compra_id` int(11) NOT NULL COMMENT 'Compra a la que pertenece',
  `pedido_detalle_id` int(11) NOT NULL COMMENT 'Detalle del pedido relacionado',
  `clasificacion_venta` varchar(100) DEFAULT NULL COMMENT 'Clasificaci??n de venta del producto',
  `proveedor` varchar(200) NOT NULL COMMENT 'Proveedor que suministrar?? este producto',
  `cantidad_solicitada_compra` int(11) NOT NULL COMMENT 'Cantidad solicitada en esta compra',
  `fecha_entrega_estimada` date DEFAULT NULL COMMENT 'Fecha estimada de entrega',
  `clasificacion_vie` varchar(100) DEFAULT NULL COMMENT 'Clasificaci??n o TipoRiesgo del producto desde Indigo',
  `precio_unitario_compra` decimal(10,2) DEFAULT NULL COMMENT 'Precio unitario acordado',
  `observaciones` text DEFAULT NULL COMMENT 'Observaciones espec??ficas del producto',
  `estado` enum('pendiente','en_transito','confirmado','recibida','cancelada') DEFAULT 'pendiente',
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_compras_detalle_compra` (`compra_id`),
  KEY `idx_compras_detalle_pedido_detalle` (`pedido_detalle_id`),
  KEY `idx_compras_detalle_proveedor` (`proveedor`),
  KEY `idx_compras_detalle_compra_id` (`compra_id`),
  KEY `idx_compras_detalle_pedido_detalle_id` (`pedido_detalle_id`),
  KEY `idx_compras_detalle_estado` (`estado`),
  KEY `idx_compras_detalle_compra_estado` (`compra_id`,`estado`),
  CONSTRAINT `compras_detalle_ibfk_1` FOREIGN KEY (`compra_id`) REFERENCES `compras` (`id`) ON DELETE CASCADE,
  CONSTRAINT `compras_detalle_ibfk_2` FOREIGN KEY (`pedido_detalle_id`) REFERENCES `pedidos_detalle` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Detalle de productos en cada orden de compra';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pedidos`
--

DROP TABLE IF EXISTS `pedidos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pedidos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `numero_pedido` varchar(50) NOT NULL,
  `proveedor` varchar(200) NOT NULL,
  `fecha_pedido` date NOT NULL,
  `fecha_esperada` date DEFAULT NULL,
  `fecha_recibido` datetime DEFAULT NULL,
  `estado` enum('pendiente','en_proceso','recibido','aprobado','rechazado','cancelado') DEFAULT 'pendiente',
  `total_articulos` int(11) DEFAULT 0,
  `observaciones` text DEFAULT NULL,
  `solicitado_por` int(11) NOT NULL,
  `recibido_por` int(11) DEFAULT NULL,
  `aprobado_por` int(11) DEFAULT NULL,
  `cancelado_por` int(11) DEFAULT NULL COMMENT 'Usuario que cancel?? el pedido',
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero_pedido` (`numero_pedido`),
  UNIQUE KEY `UK_pedidos_numero_pedido` (`numero_pedido`),
  KEY `solicitado_por` (`solicitado_por`),
  KEY `recibido_por` (`recibido_por`),
  KEY `aprobado_por` (`aprobado_por`),
  KEY `idx_pedidos_estado` (`estado`),
  KEY `idx_pedidos_fecha` (`fecha_pedido`),
  KEY `fk_pedidos_cancelado_por` (`cancelado_por`),
  KEY `idx_pedidos_estado_fecha` (`estado`,`fecha_pedido`),
  KEY `idx_pedidos_numero` (`numero_pedido`),
  KEY `idx_pedidos_solicitado_por` (`solicitado_por`),
  CONSTRAINT `fk_pedidos_cancelado_por` FOREIGN KEY (`cancelado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pedidos_ibfk_1` FOREIGN KEY (`solicitado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `pedidos_ibfk_2` FOREIGN KEY (`recibido_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pedidos_ibfk_3` FOREIGN KEY (`aprobado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pedidos_detalle`
--

DROP TABLE IF EXISTS `pedidos_detalle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pedidos_detalle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pedido_id` int(11) DEFAULT NULL,
  `codigo_producto` varchar(100) NOT NULL COMMENT 'C??digo INVIMA del producto',
  `producto_nombre` varchar(200) NOT NULL COMMENT 'Nombre del producto',
  `producto_tipo` varchar(100) DEFAULT NULL COMMENT 'Tipo de producto',
  `producto_marca` varchar(100) DEFAULT NULL COMMENT 'Marca del producto',
  `producto_promedio` varchar(50) DEFAULT NULL COMMENT 'Promedio de consumo',
  `producto_rotacion` varchar(20) DEFAULT NULL COMMENT 'Tipo de rotaci??n: bajo, media, alta',
  `codigo_sanitario` varchar(100) DEFAULT NULL COMMENT 'CUM de INVIMA diligenciado en recepci??n',
  `cum_recibido` varchar(50) DEFAULT NULL,
  `cantidad_solicitada` int(11) NOT NULL,
  `cantidad_recibida` int(11) DEFAULT 0,
  `numero_lote` varchar(50) DEFAULT NULL,
  `fecha_vencimiento` date DEFAULT NULL,
  `precio_unitario` decimal(10,2) DEFAULT NULL,
  `estado` enum('pendiente','en_transito','parcial','completo','recibido','rechazado') DEFAULT 'pendiente' COMMENT 'Estado del detalle del pedido',
  `aspecto_cumple` tinyint(1) DEFAULT NULL COMMENT 'Evaluaci??n de aspecto: 1=Cumple, 0=No Cumple',
  `embalaje_cumple` tinyint(1) DEFAULT NULL COMMENT 'Evaluaci??n de embalaje: 1=Cumple, 0=No Cumple',
  `cadena_frio_temperatura` decimal(5,2) DEFAULT NULL COMMENT 'Temperatura de cadena de fr??o (puede ser positiva o negativa)',
  `contenido_cumple` tinyint(1) DEFAULT NULL COMMENT 'Evaluaci??n de contenido: 1=Cumple, 0=No Cumple',
  `concepto_recepcion` enum('aceptado','rechazado') DEFAULT NULL COMMENT 'Concepto final de recepci??n',
  `recibido_por` int(11) DEFAULT NULL COMMENT 'Usuario que realiz?? la recepci??n t??cnica',
  `observaciones` text DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `pedido_id` (`pedido_id`),
  KEY `recibido_por` (`recibido_por`),
  KEY `idx_pedidos_detalle_codigo_producto` (`codigo_producto`),
  KEY `idx_pedidos_detalle_pedido_id` (`pedido_id`),
  KEY `idx_pedidos_detalle_codigo` (`codigo_producto`),
  KEY `idx_pedidos_detalle_pedido_estado` (`pedido_id`,`estado`),
  KEY `idx_pedidos_detalle_estado` (`estado`),
  CONSTRAINT `pedidos_detalle_ibfk_1` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pedidos_detalle_ibfk_2` FOREIGN KEY (`recibido_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=201 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `productos_replica`
--

DROP TABLE IF EXISTS `productos_replica`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `productos_replica` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(50) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `tipo_producto` varchar(100) DEFAULT NULL,
  `codigo_agrupador` varchar(50) DEFAULT NULL,
  `agrupador` varchar(255) DEFAULT NULL,
  `fabricante` varchar(255) DEFAULT NULL,
  `unidad_empaque` varchar(100) DEFAULT NULL,
  `costo_promedio` decimal(14,2) DEFAULT NULL,
  `ultimo_costo` decimal(14,2) DEFAULT NULL,
  `precio_venta` decimal(14,2) DEFAULT NULL,
  `estado` varchar(50) DEFAULT 'ACTIVO',
  `tipo_riesgo` varchar(100) DEFAULT NULL,
  `concentracion` varchar(255) DEFAULT NULL,
  `registro_sanitario` varchar(100) DEFAULT NULL,
  `presentacion` varchar(255) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_producto_codigo` (`codigo`),
  KEY `idx_producto_agrupador` (`codigo_agrupador`),
  KEY `idx_producto_estado` (`estado`),
  KEY `idx_producto_nombre` (`nombre`(100))
) ENGINE=InnoDB AUTO_INCREMENT=1001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='RÔö£┬«plica local de productos desde SQL Server / Fabric';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `recepciones_historico`
--

DROP TABLE IF EXISTS `recepciones_historico`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `recepciones_historico` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `numero_recepcion` varchar(50) DEFAULT NULL COMMENT 'N??mero de recepci??n generado',
  `compra_id` int(11) NOT NULL COMMENT 'ID de la orden de compra',
  `numero_orden_compra` varchar(50) DEFAULT NULL COMMENT 'N??mero de la orden de compra',
  `oc_indigo` varchar(50) DEFAULT NULL COMMENT 'OC de Indigo',
  `fecha_recepcion` datetime NOT NULL COMMENT 'Fecha y hora de la recepci??n',
  `recibido_por` int(11) NOT NULL COMMENT 'ID del usuario que recibi??',
  `total_items` int(11) DEFAULT 0 COMMENT 'Total de items recibidos',
  `observaciones` text DEFAULT NULL COMMENT 'Observaciones generales de la recepci??n',
  `estado` varchar(20) DEFAULT 'completa' COMMENT 'Estado de la recepci??n: completa, parcial',
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_compra_id` (`compra_id`),
  KEY `idx_numero_recepcion` (`numero_recepcion`),
  KEY `idx_fecha_recepcion` (`fecha_recepcion`),
  KEY `idx_recibido_por` (`recibido_por`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Historial de recepciones t??cnicas';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `recepciones_historico_detalle`
--

DROP TABLE IF EXISTS `recepciones_historico_detalle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `recepciones_historico_detalle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recepcion_id` int(11) NOT NULL COMMENT 'ID de la recepci??n en recepciones_historico',
  `pedido_detalle_id` int(11) NOT NULL COMMENT 'ID del detalle del pedido',
  `codigo_producto` varchar(50) DEFAULT NULL COMMENT 'C??digo del producto',
  `producto_nombre` varchar(255) DEFAULT NULL COMMENT 'Nombre del producto',
  `cantidad_solicitada` decimal(10,2) DEFAULT 0.00 COMMENT 'Cantidad solicitada',
  `cantidad_recibida` decimal(10,2) DEFAULT 0.00 COMMENT 'Cantidad recibida',
  `numero_lote` varchar(100) DEFAULT NULL COMMENT 'N??mero de lote',
  `fecha_vencimiento` date DEFAULT NULL COMMENT 'Fecha de vencimiento',
  `codigo_sanitario` varchar(100) DEFAULT NULL COMMENT 'C??digo sanitario (CUM o Registro Sanitario)',
  `aspecto_cumple` tinyint(1) DEFAULT NULL COMMENT '1=Cumple, 0=No Cumple',
  `embalaje_cumple` tinyint(1) DEFAULT NULL COMMENT '1=Cumple, 0=No Cumple',
  `contenido_cumple` tinyint(1) DEFAULT NULL COMMENT '1=Cumple, 0=No Cumple',
  `cadena_frio_temperatura` decimal(5,2) DEFAULT NULL COMMENT 'Temperatura de cadena de fr??o en ??C',
  `concepto_recepcion` varchar(20) DEFAULT NULL COMMENT 'aceptado, rechazado',
  `es_medicamento_vital` tinyint(1) DEFAULT 0 COMMENT 'Es medicamento vital no disponible (MVD)',
  `mvd_ium` varchar(50) DEFAULT NULL COMMENT 'IUM del medicamento vital no disponible',
  `mvd_solicitante` varchar(255) DEFAULT NULL COMMENT 'Solicitante/importador autorizado',
  `mvd_principio_activo` varchar(255) DEFAULT NULL COMMENT 'Principio activo del MVD',
  `mvd_forma_farmaceutica` varchar(150) DEFAULT NULL COMMENT 'Forma farmaceutica del MVD',
  `mvd_presentacion_comercial` varchar(255) DEFAULT NULL COMMENT 'Presentacion comercial del MVD',
  `mvd_fecha_autorizacion` date DEFAULT NULL COMMENT 'Fecha de autorizacion INVIMA del MVD',
  `observaciones_recepcion` text DEFAULT NULL COMMENT 'Observaciones de la recepci??n t??cnica',
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_recepcion_id` (`recepcion_id`),
  KEY `idx_pedido_detalle_id` (`pedido_detalle_id`),
  KEY `idx_codigo_producto` (`codigo_producto`),
  CONSTRAINT `fk_recepcion_historico_detalle` FOREIGN KEY (`recepcion_id`) REFERENCES `recepciones_historico` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Detalle del historial de recepciones t??cnicas';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-01  9:06:32
