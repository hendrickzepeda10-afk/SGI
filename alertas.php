<?php
// Activar reporte de errores para depuración
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';

$database = new Database();
$pdo = $database->getConnection();

$error = "";
$success = "";

// Lógica para procesar la eliminación del producto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_product') {
    $productId = (int)($_POST['product_id'] ?? 0);
    
    if ($productId > 0) {
        try {
            // Desactivar el producto (Soft Delete para mantener la integridad referencial)
            $stmtDel = $pdo->prepare("UPDATE products SET is_active = 0 WHERE id = ?");
            $stmtDel->execute([$productId]);
            
            $_SESSION['message'] = "El producto ha sido eliminado del sistema correctamente.";
            header("Location: alertas.php");
            exit;
        } catch (PDOException $e) {
            $error = "Error al intentar eliminar el producto: " . $e->getMessage();
        }
    }
}

if (isset($_SESSION['message'])) {
    $success = $_SESSION['message'];
    unset($_SESSION['message']);
}

// Verificar si existe la columna min_stock en products y si no, añadirla de forma segura
try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM products LIKE 'min_stock'");
    if ($colCheck->rowCount() === 0) {
        $pdo->exec("ALTER TABLE products ADD COLUMN min_stock DECIMAL(12,4) DEFAULT 10.00");
    }
} catch (PDOException $e) {
    // Continuar si ya existe o hay advertencia
}

// Consultar alertas de stock
$alertas = [];
$totalAgotados = 0;
$totalBajoStock = 0;

try {
    $query = "
        SELECT 
            p.id,
            p.sku,
            p.name as product_name,
            p.unit_of_measure,
            COALESCE(p.min_stock, 10.00) as min_stock,
            COALESCE(s.current_stock, 0) as current_stock,
            l.name as location_name,
            l.id as location_id
        FROM products p
        LEFT JOIN inventory_stock s ON p.id = s.product_id
        LEFT JOIN locations l ON s.location_id = l.id
        WHERE p.is_active = 1 
          AND COALESCE(s.current_stock, 0) <= COALESCE(p.min_stock, 10.00)
        ORDER BY COALESCE(s.current_stock, 0) ASC, p.name ASC
    ";
    $stmt = $pdo->query($query);
    $alertas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($alertas as $item) {
        if ((float)$item['current_stock'] <= 0) {
            $totalAgotados++;
        } else {
            $totalBajoStock++;
        }
    }
} catch (PDOException $e) {
    $error = "Error al cargar las alertas de stock: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SGI | Alertas de Stock</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- Archivo CSS centralizado -->
    <link rel="stylesheet" href="css/estilo.css">
</head>
<body>

    <?php include './sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <div class="mb-4">
            <h3 class="fw-bold text-white mb-1">Alertas de Stock</h3>
            <p class="text-secondary mb-0">Monitoreo de insumos críticos, productos agotados y niveles por debajo del stock mínimo</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Tarjetas de Resumen -->
        <div class="row g-4 mb-4">
            <div class="col-md-6">
                <div class="custom-card p-3 d-flex align-items-center justify-content-between border-start border-danger border-4">
                    <div>
                        <span class="text-uppercase text-secondary fw-bold" style="font-size: 0.75rem;">Productos Agotados</span>
                        <h3 class="fw-bold text-danger mb-0 mt-1"><?= $totalAgotados ?></h3>
                    </div>
                    <div class="fs-1 text-danger opacity-25">
                        <i class="bi bi-slash-circle-fill"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="custom-card p-3 d-flex align-items-center justify-content-between border-start border-warning border-4">
                    <div>
                        <span class="text-uppercase text-secondary fw-bold" style="font-size: 0.75rem;">Stock por debajo del Mínimo</span>
                        <h3 class="fw-bold text-warning mb-0 mt-1"><?= $totalBajoStock ?></h3>
                    </div>
                    <div class="fs-1 text-warning opacity-25">
                        <i class="bi bi-exclamation-octagon-fill"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabla de Alertas -->
        <div class="custom-card p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-white">Listado de Insumos en Alerta</h5>
                <a href="ajustes.php" class="btn btn-outline-light btn-sm fw-semibold">
                    <i class="bi bi-sliders me-1"></i> Ir a Ajustes de Stock
                </a>
            </div>

            <div class="table-responsive">
                <table class="table table-dark-custom align-middle mb-0">
                    <thead>
                        <tr>
                            <th>SKU / INSUMO</th>
                            <th>ALMACÉN</th>
                            <th>STOCK ACTUAL</th>
                            <th>STOCK MÍNIMO</th>
                            <th>ESTADO</th>
                            <th class="text-end">ACCIÓN RÁPIDA</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($alertas) > 0): ?>
                            <?php foreach ($alertas as $item): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-white"><code><?= htmlspecialchars($item['sku']) ?></code></div>
                                        <span class="small text-secondary"><?= htmlspecialchars($item['product_name']) ?></span>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($item['location_name'] ?? 'Almacén Principal') ?>
                                    </td>
                                    <td class="fw-bold <?= ((float)$item['current_stock'] <= 0) ? 'text-danger' : 'text-warning' ?>">
                                        <?= number_format($item['current_stock'], 2) ?> <?= htmlspecialchars($item['unit_of_measure'] ?? '') ?>
                                    </td>
                                    <td>
                                        <?= number_format($item['min_stock'], 2) ?>
                                    </td>
                                    <td>
                                        <?php if ((float)$item['current_stock'] <= 0): ?>
                                            <span class="badge bg-danger bg-opacity-25 text-danger border border-danger">AGOTADO</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning bg-opacity-25 text-warning border border-warning">STOCK BAJO</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1">
                                            <a href="entradas.php" class="btn btn-sm btn-outline-success" title="Registrar Recepción">
                                                <i class="bi bi-arrow-down-left-square"></i> Recepción
                                            </a>
                                            <a href="ajustes.php" class="btn btn-sm btn-outline-secondary" title="Ajustar Stock">
                                                <i class="bi bi-sliders"></i> Ajustar
                                            </a>
                                            <!-- Botón de Eliminar -->
                                            <form method="POST" class="d-inline" onsubmit="return confirm('¿Estás seguro de que deseas eliminar el insumo «<?= htmlspecialchars($item['product_name']) ?>»?');">
                                                <input type="hidden" name="action" value="delete_product">
                                                <input type="hidden" name="product_id" value="<?= $item['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar Insumo">
                                                    <i class="bi bi-trash"></i> Eliminar
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-secondary">
                                    <i class="bi bi-check-circle fs-2 text-success d-block mb-2"></i>
                                    <strong>¡Todo en orden!</strong> No hay productos con stock por debajo del mínimo.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Botón de Cerrar Sesión flotante -->
    <div class="btn-logout-container">
        <a href="logout.php" class="btn btn-danger px-4 py-2 shadow-lg fw-medium d-flex align-items-center gap-2">
            <i class="bi bi-box-arrow-right"></i> Cerrar Sesión
        </a>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>