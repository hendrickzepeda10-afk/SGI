-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1:3306
-- Tiempo de generación: 23-09-2026 a las 15:12:27
-- Versión del servidor: 8.4.7
-- Versión de PHP: 8.3.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `sgi_inventario`
--

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `categorias`
-- (Véase abajo para la vista actual)
--
DROP VIEW IF EXISTS `categorias`;
CREATE TABLE IF NOT EXISTS `categorias` (
`id` int
,`nombre` varchar(100)
);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `categories`
--

DROP TABLE IF EXISTS `categories`;
CREATE TABLE IF NOT EXISTS `categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `categories`
--

INSERT INTO `categories` (`id`, `name`, `description`) VALUES
(1, 'Oficina', 'Artículos de oficina'),
(2, 'Limpieza', 'Productos de aseo'),
(3, 'Tecnología', 'Equipos y accesorios');

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `insumos`
-- (Véase abajo para la vista actual)
--
DROP VIEW IF EXISTS `insumos`;
CREATE TABLE IF NOT EXISTS `insumos` (
`categoria_id` int
,`codigo_barras` varchar(50)
,`estado` varchar(8)
,`id` bigint
,`nombre` varchar(150)
,`precio_compra` decimal(10,2)
,`stock_actual` int
,`stock_minimo` int
,`unidad_medida` varchar(20)
);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `inventory_adjustments`
--

DROP TABLE IF EXISTS `inventory_adjustments`;
CREATE TABLE IF NOT EXISTS `inventory_adjustments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` bigint NOT NULL,
  `location_id` int NOT NULL DEFAULT '1',
  `adjustment_type` enum('SUMAR','RESTAR','FIJAR') COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(12,4) NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `location_id` (`location_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `inventory_adjustments`
--

INSERT INTO `inventory_adjustments` (`id`, `product_id`, `location_id`, `adjustment_type`, `quantity`, `reason`, `created_at`) VALUES
(10, 8, 1, 'RESTAR', 100.0000, '', '2026-09-22 13:36:05');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `inventory_movements`
--

DROP TABLE IF EXISTS `inventory_movements`;
CREATE TABLE IF NOT EXISTS `inventory_movements` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `product_id` bigint NOT NULL,
  `location_id` int NOT NULL DEFAULT '1',
  `source_location_id` int DEFAULT '1',
  `target_location_id` int DEFAULT '1',
  `user_id` int NOT NULL,
  `movement_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'COMPRA_DIRECTA',
  `quantity` decimal(12,4) NOT NULL,
  `unit_cost` decimal(12,4) DEFAULT '0.0000',
  `reason` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `supplier` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_no` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `user_id` (`user_id`),
  KEY `location_id` (`location_id`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `inventory_movements`
--

INSERT INTO `inventory_movements` (`id`, `product_id`, `location_id`, `source_location_id`, `target_location_id`, `user_id`, `movement_type`, `quantity`, `unit_cost`, `reason`, `created_at`, `supplier`, `reference_no`, `notes`) VALUES
(24, 8, 1, 1, 1, 1, 'ALMACEN_CENTRAL', 200.0000, 0.0000, NULL, '2026-09-22 13:34:11', 'BIC', '22/09/2026', 'Nuevo Ingreso'),
(25, 8, 1, 1, NULL, 1, 'SALIDA', 200.0000, 0.0000, 'Envio a departamento de TI', '2026-09-22 13:34:46', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `inventory_stock`
--

DROP TABLE IF EXISTS `inventory_stock`;
CREATE TABLE IF NOT EXISTS `inventory_stock` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `product_id` bigint NOT NULL,
  `location_id` int NOT NULL DEFAULT '1',
  `current_stock` decimal(12,4) NOT NULL DEFAULT '0.0000',
  `weighted_average_cost` decimal(12,4) NOT NULL DEFAULT '0.0000',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_location` (`product_id`,`location_id`),
  KEY `location_id` (`location_id`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `inventory_stock`
--

INSERT INTO `inventory_stock` (`id`, `product_id`, `location_id`, `current_stock`, `weighted_average_cost`) VALUES
(25, 8, 1, 900.0000, 0.0000);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `locations`
--

DROP TABLE IF EXISTS `locations`;
CREATE TABLE IF NOT EXISTS `locations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('WAREHOUSE','DEPARTMENT') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'WAREHOUSE',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `locations`
--

INSERT INTO `locations` (`id`, `name`, `type`) VALUES
(1, 'Almacén Principal', 'WAREHOUSE');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `logs_auditoria`
--

DROP TABLE IF EXISTS `logs_auditoria`;
CREATE TABLE IF NOT EXISTS `logs_auditoria` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` int NOT NULL,
  `accion` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fecha` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `movimientos`
-- (Véase abajo para la vista actual)
--
DROP VIEW IF EXISTS `movimientos`;
CREATE TABLE IF NOT EXISTS `movimientos` (
`area_colaborador_id` int
,`cantidad` decimal(12,4)
,`fecha` timestamp
,`id` bigint
,`insumo_id` bigint
,`insumo_nombre` varchar(150)
,`motivo_justificacion` text
,`proveedor_id` int
,`sku` varchar(50)
,`tipo` varchar(100)
,`usuario_id` int
);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `products`
--

DROP TABLE IF EXISTS `products`;
CREATE TABLE IF NOT EXISTS `products` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `sku` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `barcode` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` int DEFAULT NULL,
  `unit_of_measure` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Unidad',
  `precio_compra` decimal(10,2) NOT NULL DEFAULT '0.00',
  `min_stock` int DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`),
  UNIQUE KEY `barcode` (`barcode`),
  KEY `category_id` (`category_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `products`
--

INSERT INTO `products` (`id`, `sku`, `barcode`, `name`, `category_id`, `unit_of_measure`, `precio_compra`, `min_stock`, `is_active`, `created_at`) VALUES
(8, 'WOA-220-P6', '6374823', 'Lapiz Carbon BAC', 1, 'Unidad', 0.00, 200, 1, '2026-09-22 13:32:55');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `roles`
--

DROP TABLE IF EXISTS `roles`;
CREATE TABLE IF NOT EXISTS `roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre` (`nombre`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `roles`
--

INSERT INTO `roles` (`id`, `nombre`) VALUES
(1, 'Administrador'),
(2, 'Operador');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `solicitudes`
--

DROP TABLE IF EXISTS `solicitudes`;
CREATE TABLE IF NOT EXISTS `solicitudes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `solicitante` varchar(100) NOT NULL,
  `departamento` varchar(100) NOT NULL,
  `product_id` int NOT NULL,
  `cantidad` int NOT NULL,
  `prioridad` enum('Baja','Media','Alta','Urgente') DEFAULT 'Media',
  `motivo` text,
  `estado` enum('Pendiente','Aprobado','Rechazado','Entregado') DEFAULT 'Pendiente',
  `fecha_solicitud` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `transfers`
--

DROP TABLE IF EXISTS `transfers`;
CREATE TABLE IF NOT EXISTS `transfers` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `transfer_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_location_id` int NOT NULL,
  `target_location_id` int NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `user_id` int NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transfer_code` (`transfer_code`),
  KEY `source_location_id` (`source_location_id`),
  KEY `target_location_id` (`target_location_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'Operador',
  `role_id` int DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `users`
--

INSERT INTO `users` (`id`, `username`, `full_name`, `email`, `password_hash`, `role`, `role_id`, `is_active`, `created_at`) VALUES
(2, 'halvarez', 'Hendrick Zepeda', 'hendrickZepeda@covelo.com', '$2y$10$acHJcg0UvdlXL0hlvyYSUeo.Up9ZOzgJhvTVMPSU/Xnx8YTyt52h6', 'Administrador', NULL, 1, '2026-09-17 19:32:24'),
(3, 'JJoel', 'Joel Rubio', 'JoelRodriguez@covelo.com', '$2y$10$o9uu0p5mPCCq7foNatLEou8gU1RBTSzdtWSVHSFPrVrp9.P2AFw6y', 'Almacenista', NULL, 1, '2026-09-22 13:38:16');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

DROP TABLE IF EXISTS `usuarios`;
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `rol_id` int NOT NULL,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `creado_on` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `rol_id` (`rol_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`id`, `rol_id`, `nombre`, `email`, `password`, `creado_on`) VALUES
(1, 1, 'prueba1', 'prueba1@sgi.com', '$2y$10$wfOyyQ8Y4ASc/.BhjVx2be85nwzppm2gfTeGNCJFXuM/HITaypKka', '2026-09-17 15:46:30');

-- --------------------------------------------------------

--
-- Estructura para la vista `categorias`
--
DROP TABLE IF EXISTS `categorias`;

DROP VIEW IF EXISTS `categorias`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `categorias`  AS SELECT `categories`.`id` AS `id`, `categories`.`name` AS `nombre` FROM `categories` ;

-- --------------------------------------------------------

--
-- Estructura para la vista `insumos`
--
DROP TABLE IF EXISTS `insumos`;

DROP VIEW IF EXISTS `insumos`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `insumos`  AS SELECT `products`.`id` AS `id`, `products`.`sku` AS `codigo_barras`, `products`.`name` AS `nombre`, `products`.`category_id` AS `categoria_id`, `products`.`unit_of_measure` AS `unidad_medida`, `products`.`precio_compra` AS `precio_compra`, 0 AS `stock_actual`, `products`.`min_stock` AS `stock_minimo`, (case when (`products`.`is_active` = 1) then 'ACTIVO' else 'INACTIVO' end) AS `estado` FROM `products` ;

-- --------------------------------------------------------

--
-- Estructura para la vista `movimientos`
--
DROP TABLE IF EXISTS `movimientos`;

DROP VIEW IF EXISTS `movimientos`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `movimientos`  AS SELECT `m`.`id` AS `id`, `m`.`product_id` AS `insumo_id`, `m`.`user_id` AS `usuario_id`, 1 AS `proveedor_id`, 1 AS `area_colaborador_id`, `m`.`movement_type` AS `tipo`, `m`.`quantity` AS `cantidad`, `m`.`reason` AS `motivo_justificacion`, `m`.`created_at` AS `fecha`, `p`.`sku` AS `sku`, `p`.`name` AS `insumo_nombre` FROM (`inventory_movements` `m` left join `products` `p` on((`m`.`product_id` = `p`.`id`))) ;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `inventory_adjustments`
--
ALTER TABLE `inventory_adjustments`
  ADD CONSTRAINT `inventory_adjustments_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inventory_adjustments_ibfk_2` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `inventory_movements`
--
ALTER TABLE `inventory_movements`
  ADD CONSTRAINT `inventory_movements_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  ADD CONSTRAINT `inventory_movements_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `usuarios` (`id`),
  ADD CONSTRAINT `inventory_movements_ibfk_3` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`);

--
-- Filtros para la tabla `inventory_stock`
--
ALTER TABLE `inventory_stock`
  ADD CONSTRAINT `inventory_stock_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inventory_stock_ibfk_2` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `logs_auditoria`
--
ALTER TABLE `logs_auditoria`
  ADD CONSTRAINT `logs_auditoria_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`);

--
-- Filtros para la tabla `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `transfers`
--
ALTER TABLE `transfers`
  ADD CONSTRAINT `transfers_ibfk_1` FOREIGN KEY (`source_location_id`) REFERENCES `locations` (`id`),
  ADD CONSTRAINT `transfers_ibfk_2` FOREIGN KEY (`target_location_id`) REFERENCES `locations` (`id`),
  ADD CONSTRAINT `transfers_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `usuarios` (`id`);

--
-- Filtros para la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD CONSTRAINT `usuarios_ibfk_1` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
