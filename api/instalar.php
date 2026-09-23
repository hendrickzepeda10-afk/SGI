<?php
$host = "localhost";
$user = "root";
$pass = "";

try {
    $pdo = new PDO("mysql:host=$host", $user,$pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Crear Base de Datos
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `sgi_inventario` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    $pdo->exec("USE `sgi_inventario`;");

    // Limpiar vistas previas para evitar conflictos
    $viewsToDrop = [
        'products', 'insumos', 'categories', 'categorias', 
        'inventory_movements', 'movimientos', 'inventory_adjustments'
    ];
    foreach ($viewsToDrop as $view) {$pdo->exec("DROP VIEW IF EXISTS `$view`;");
    }

    // Tablas Físicas Principales (Ejecutadas por separado para evitar fallos de sintaxis en PDO)
    $tables = [
        "CREATE TABLE IF NOT EXISTS roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(50) NOT NULL UNIQUE
        ) ENGINE=InnoDB;",

        "CREATE TABLE IF NOT EXISTS usuarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rol_id INT NOT NULL,
            nombre VARCHAR(100) NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            creado_on TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (rol_id) REFERENCES roles(id)
        ) ENGINE=InnoDB;",

        "CREATE TABLE IF NOT EXISTS categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            description TEXT NULL
        ) ENGINE=InnoDB;",

        "CREATE TABLE IF NOT EXISTS locations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            type ENUM('WAREHOUSE', 'DEPARTMENT') NOT NULL DEFAULT 'WAREHOUSE'
        ) ENGINE=InnoDB;",

        "CREATE TABLE IF NOT EXISTS products (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(50) NOT NULL UNIQUE,
            barcode VARCHAR(100) NULL UNIQUE,
            name VARCHAR(150) NOT NULL,
            category_id INT NULL,
            unit_of_measure VARCHAR(20) NOT NULL DEFAULT 'Unidad',
            precio_compra DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            min_stock INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
        ) ENGINE=InnoDB;",

        "CREATE TABLE IF NOT EXISTS inventory_stock (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT NOT NULL,
            location_id INT NOT NULL DEFAULT 1,
            current_stock DECIMAL(12, 4) NOT NULL DEFAULT 0.0000,
            weighted_average_cost DECIMAL(12, 4) NOT NULL DEFAULT 0.0000,
            UNIQUE KEY uq_product_location (product_id, location_id),
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB;",

        "CREATE TABLE IF NOT EXISTS inventory_movements (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT NOT NULL,
            location_id INT NOT NULL DEFAULT 1,
            source_location_id INT NULL DEFAULT 1,
            target_location_id INT NULL DEFAULT 1,
            user_id INT NOT NULL,
            movement_type ENUM('ENTRADA', 'SALIDA', 'TRANSFERENCIA', 'AJUSTE_POSITIVO', 'AJUSTE_NEGATIVO') NOT NULL,
            quantity DECIMAL(12, 4) NOT NULL,
            unit_cost DECIMAL(12, 4) DEFAULT 0.0000,
            reason TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id),
            FOREIGN KEY (user_id) REFERENCES usuarios(id),
            FOREIGN KEY (location_id) REFERENCES locations(id)
        ) ENGINE=InnoDB;",

        "CREATE TABLE IF NOT EXISTS transfers (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            transfer_code VARCHAR(50) NOT NULL UNIQUE,
            source_location_id INT NOT NULL,
            target_location_id INT NOT NULL,
            reason TEXT NULL,
            user_id INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (source_location_id) REFERENCES locations(id),
            FOREIGN KEY (target_location_id) REFERENCES locations(id),
            FOREIGN KEY (user_id) REFERENCES usuarios(id)
        ) ENGINE=InnoDB;",

        "CREATE TABLE IF NOT EXISTS logs_auditoria (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            accion VARCHAR(255) NOT NULL,
            fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ) ENGINE=InnoDB;"
    ];

    foreach ($tables as$sql) {
        $pdo->exec($sql);
    }

    // Vistas de compatibilidad enriquecidas
    $pdo->exec("CREATE VIEW categorias AS SELECT id, name AS nombre FROM categories;");
    $pdo->exec("CREATE VIEW insumos AS SELECT id, sku AS codigo_barras, name AS nombre, category_id AS categoria_id, unit_of_measure AS unidad_medida, precio_compra, 0 AS stock_actual, min_stock AS stock_minimo, CASE WHEN is_active = 1 THEN 'ACTIVO' ELSE 'INACTIVO' END AS estado FROM products;");
    $pdo->exec("CREATE VIEW movimientos AS SELECT m.id, m.product_id AS insumo_id, m.user_id AS usuario_id, 1 AS proveedor_id, 1 AS area_colaborador_id, m.movement_type AS tipo, m.quantity AS cantidad, m.reason AS motivo_justificacion, m.created_at AS fecha, p.sku, p.name AS insumo_nombre FROM inventory_movements m LEFT JOIN products p ON m.product_id = p.id;");

    // Datos iniciales
    $pdo->exec("INSERT IGNORE INTO roles (id, nombre) VALUES (1, 'Administrador'), (2, 'Operador');");
    
    $passwordHash = password_hash('12345678', PASSWORD_BCRYPT);
    $stmt =$pdo->prepare("INSERT INTO usuarios (id, rol_id, nombre, email, password) 
                           VALUES (1, 1, 'prueba1', 'prueba1@sgi.com', ?) 
                           ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), password = VALUES(password)");
    $stmt->execute([$passwordHash]);

    $pdo->exec("INSERT IGNORE INTO categories (id, name, description) VALUES (1, 'Oficina', 'Artículos de oficina'), (2, 'Limpieza', 'Productos de aseo'), (3, 'Tecnología', 'Equipos y accesorios');");
    $pdo->exec("INSERT IGNORE INTO locations (id, name, type) VALUES (1, 'Almacén Principal', 'WAREHOUSE');");

    echo "<div style='font-family: sans-serif; padding: 20px; background: #d1e7dd; color: #0f5132; border-radius: 8px;'>";
    echo "<h3>¡Base de datos sincronizada con éxito!</h3>";
    echo "<p>Se han actualizado las vistas y relaciones para que el historial muestre los registros correctamente.</p>";
    echo "<a href='../salidas.php' style='display:inline-block; padding: 10px 15px; background: #0f5132; color: #fff; text-decoration: none; border-radius: 5px;'>Ir a Distribución y Salidas</a>";
    echo "</div>";

} catch (PDOException $e) {
    echo "<div style='font-family: sans-serif; padding: 20px; background: #f8d7da; color: #842029; border-radius: 8px;'>";
    echo "<h3>Error de instalación:</h3> " . htmlspecialchars($e->getMessage());
    echo "</div>";
}
?>