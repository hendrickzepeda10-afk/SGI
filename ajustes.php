<?php
// Activar reporte de errores para depuración
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// INICIAR SESIÓN para poder pasar mensajes de éxito o error tras la redirección
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';

$database = new Database();
$pdo = $database->getConnection();

// Asegurar que la tabla inventory_adjustments exista en la base de datos
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_adjustments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id BIGINT NOT NULL,
        location_id INT NOT NULL DEFAULT 1,
        adjustment_type ENUM('SUMAR', 'RESTAR', 'FIJAR') NOT NULL,
        quantity DECIMAL(12, 4) NOT NULL,
        reason VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
        FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;");
} catch (PDOException $e) {
    // Continuar si ya existe
}

$DEFAULT_LOCATION_ID = 1;

// Procesar acciones (POST o GET para eliminar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verificar si es acción de eliminar
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $adjustment_id = (int)($_POST['adjustment_id'] ?? 0);
        $revert_stock  = isset($_POST['revert_stock']) ? true : false;

        if ($adjustment_id > 0) {
            try {
                $pdo->beginTransaction();

                if ($revert_stock) {
                    // Obtener los datos del ajuste antes de borrarlo
                    $stmtAdjInfo = $pdo->prepare("SELECT product_id, location_id, adjustment_type, quantity FROM inventory_adjustments WHERE id = ? FOR UPDATE");
                    $stmtAdjInfo->execute([$adjustment_id]);
                    $adjData = $stmtAdjInfo->fetch();

                    if ($adjData) {
                        $prod_id   = $adjData['product_id'];
                        $loc_id    = $adjData['location_id'];
                        $tipo      = $adjData['adjustment_type'];
                        $cant      = (float)$adjData['quantity'];

                        // Obtener stock actual
                        $stmtStock = $pdo->prepare("SELECT current_stock FROM inventory_stock WHERE product_id = ? AND location_id = ? FOR UPDATE");
                        $stmtStock->execute([$prod_id, $loc_id]);
                        $currStockRec = $stmtStock->fetch();
                        $stockActual  = $currStockRec ? (float)$currStockRec['current_stock'] : 0.00;

                        // Revertir el efecto del ajuste eliminado
                        $nuevoStockRevertido = $stockActual;
                        if ($tipo === 'SUMAR') {
                            $nuevoStockRevertido = max(0, $stockActual - $cant);
                        } elseif ($tipo === 'RESTAR') {
                            $nuevoStockRevertido = $stockActual + $cant;
                        }

                        if ($tipo !== 'FIJAR') {
                            $stmtUpsert = $pdo->prepare("
                                INSERT INTO inventory_stock (product_id, location_id, current_stock) 
                                VALUES (?, ?, ?) 
                                ON DUPLICATE KEY UPDATE current_stock = VALUES(current_stock)
                            ");
                            $stmtUpsert->execute([$prod_id, $loc_id, $nuevoStockRevertido]);
                        }
                    }
                }

                // Eliminar el registro del historial
                $stmtDel = $pdo->prepare("DELETE FROM inventory_adjustments WHERE id = ?");
                $stmtDel->execute([$adjustment_id]);

                $pdo->commit();
                $_SESSION['mensaje'] = "El registro de ajuste ha sido eliminado del historial" . ($revert_stock ? " y el stock fue revertido correctamente." : ".");
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $_SESSION['error'] = "<strong>Error al eliminar el ajuste:</strong> " . $e->getMessage();
            }
        }
        header("Location: ajustes.php");
        exit();
    }

    // Procesar nuevo ajuste de stock
    $product_id       = (int)($_POST['product_id'] ?? 0);
    $location_id      = (int)($_POST['location_id'] ?? $DEFAULT_LOCATION_ID);
    $adjustment_type  = $_POST['adjustment_type'] ?? '';
    $quantity         = (float)($_POST['quantity'] ?? 0);
    $reason           = trim($_POST['reason'] ?? '');

    if ($product_id > 0 && in_array($adjustment_type, ['SUMAR', 'RESTAR', 'FIJAR']) && $quantity >= 0) {
        try {
            $pdo->beginTransaction();

            $stmtStock = $pdo->prepare("SELECT current_stock FROM inventory_stock WHERE product_id = ? AND location_id = ? FOR UPDATE");
            $stmtStock->execute([$product_id, $location_id]);
            $currentRecord = $stmtStock->fetch();

            $stockActualBD = $currentRecord ? (float)$currentRecord['current_stock'] : 0.00;
            $nuevoStock = $stockActualBD;

            if ($adjustment_type === 'SUMAR') {
                $nuevoStock = $stockActualBD + $quantity;
            } elseif ($adjustment_type === 'RESTAR') {
                if ($quantity > $stockActualBD) {
                    throw new Exception("No se puede restar $quantity. El stock actual disponible es de solo " . number_format($stockActualBD, 2) . ".");
                }
                $nuevoStock = $stockActualBD - $quantity;
            } elseif ($adjustment_type === 'FIJAR') {
                $nuevoStock = $quantity;
            }

            $stmtUpsert = $pdo->prepare("
                INSERT INTO inventory_stock (product_id, location_id, current_stock) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE current_stock = VALUES(current_stock)
            ");
            $stmtUpsert->execute([$product_id, $location_id, $nuevoStock]);

            $stmtLog = $pdo->prepare("
                INSERT INTO inventory_adjustments (product_id, location_id, adjustment_type, quantity, reason) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmtLog->execute([$product_id, $location_id, $adjustment_type, $quantity, $reason]);

            $pdo->commit();
            $_SESSION['mensaje'] = "Ajuste de stock registrado correctamente. Stock actualizado a " . number_format($nuevoStock, 2);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error'] = "<strong>Error al procesar ajuste:</strong> " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Por favor selecciona un insumo válido, tipo de ajuste y cantidad.";
    }

    header("Location: ajustes.php");
    exit();
}

// Recuperar mensajes de sesión y limpiarlos
$mensaje = $_SESSION['mensaje'] ?? "";
$error   = $_SESSION['error'] ?? "";
unset($_SESSION['mensaje'], $_SESSION['error']);

// Consultar Productos
$products = [];
try {
    $stmtProd = $pdo->query("SELECT id, sku, name, unit_of_measure FROM products WHERE is_active = 1 ORDER BY name ASC");
    $products = $stmtProd->fetchAll();
} catch (PDOException $e) {
    $error = "Error al cargar productos: " . $e->getMessage();
}

// Consultar Ubicaciones
$locations = [];
try {
    $stmtLoc = $pdo->query("SELECT id, name FROM locations ORDER BY name ASC");
    $locations = $stmtLoc->fetchAll();
} catch (PDOException $e) {
    $locations = [['id' => 1, 'name' => 'Almacén Principal']];
}

// Consultar Historial de Ajustes
$adjustments = [];
try {
    $queryAdj = "
        SELECT a.*, p.sku, p.name as product_name, l.name as location_name 
        FROM inventory_adjustments a 
        JOIN products p ON a.product_id = p.id 
        LEFT JOIN locations l ON a.location_id = l.id 
        ORDER BY a.created_at DESC 
        LIMIT 50
    ";
    $stmtAdj = $pdo->query($queryAdj);
    $adjustments = $stmtAdj->fetchAll();
} catch (PDOException $e) {
    $error = "Error al cargar el historial de ajustes: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SGI | Ajustes de Stock</title>
    <link rel="stylesheet" href="css/temas.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- Enlace a la hoja de estilos externa unificada -->
    <link rel="stylesheet" href="css/estilo.css">
</head>
<body>

    <?php 
    include './sidebar.php'; 
    ?>

    <!-- Main Content -->
    <main class="main-content">
        <div class="mb-4 animate-fade-in-up">
            <h3 class="fw-bold text-white mb-1">Ajustes de Stock</h3>
            <p class="text-muted mb-0">Corrección de inventario, conciliación de conteo físico y registro de movimientos manuales</p>
        </div>

        <?php if (!empty($mensaje)): ?>
            <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i><?= $mensaje ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Formulario de Nuevo Ajuste -->
            <div class="col-lg-4">
                <div class="custom-card">
                    <h5 class="fw-bold mb-3 text-white"><i class="bi bi-sliders2 me-2 text-warning"></i>Registrar Ajuste</h5>
                    <form action="ajustes.php" method="POST">
                        <div class="mb-3">
                            <label class="form-label text-secondary fw-medium">Insumo / Producto *</label>
                            <select name="product_id" class="form-select" required>
                                <option value="">-- Seleccionar producto --</option>
                                <?php foreach ($products as $prod): ?>
                                    <option value="<?= $prod['id'] ?>">
                                        <?= htmlspecialchars($prod['sku'] . ' - ' . $prod['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-secondary fw-medium">Almacén / Ubicación</label>
                            <select name="location_id" class="form-select" required>
                                <?php foreach ($locations as $loc): ?>
                                    <option value="<?= $loc['id'] ?>"><?= htmlspecialchars($loc['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-secondary fw-medium">Tipo de Ajuste *</label>
                            <select name="adjustment_type" class="form-select" required>
                                <option value="SUMAR">(+) Sumar al Stock</option>
                                <option value="RESTAR">(-) Restar del Stock</option>
                                <option value="FIJAR">(=) Fijar Stock Exacto (Conteo Físico)</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-secondary fw-medium">Cantidad *</label>
                            <input type="number" step="0.01" name="quantity" class="form-control" min="0" required placeholder="0.00">
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-secondary fw-medium">Motivo / Observación</label>
                            <textarea name="reason" class="form-control" rows="2" placeholder="Ej: Conteo físico mensual, merma, ajuste por rotura..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-warning w-100 fw-semibold text-dark">
                            <i class="bi bi-check2-circle me-1"></i> Aplicar Ajuste
                        </button>
                    </form>
                </div>
            </div>

            <!-- Tabla de Historial de Ajustes -->
            <div class="col-lg-8">
                <div class="custom-card">
                    <h5 class="fw-bold mb-3 text-white">Historial de Ajustes</h5>
                    <div class="table-responsive">
                        <table class="table table-dark table-dark-custom table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>FECHA</th>
                                    <th>SKU / INSUMO</th>
                                    <th>TIPO</th>
                                    <th>CANTIDAD</th>
                                    <th>MOTIVO</th>
                                    <th class="text-end">ACCIONES</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($adjustments) > 0): ?>
                                    <?php foreach ($adjustments as $adj): ?>
                                        <tr>
                                            <td>
                                                <small class="text-muted"><?= htmlspecialchars($adj['created_at']) ?></small>
                                            </td>
                                            <td>
                                                <div class="fw-bold text-white"><code><?= htmlspecialchars($adj['sku']) ?></code></div>
                                                <span class="small text-secondary"><?= htmlspecialchars($adj['product_name']) ?></span>
                                            </td>
                                            <td>
                                                <?php if ($adj['adjustment_type'] === 'SUMAR'): ?>
                                                    <span class="badge bg-success-subtle text-success border border-success">SUMAR</span>
                                                <?php elseif ($adj['adjustment_type'] === 'RESTAR'): ?>
                                                    <span class="badge bg-danger-subtle text-danger border border-danger">RESTAR</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning-subtle text-warning border border-warning">FIJAR</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fw-bold text-white">
                                                <?= number_format($adj['quantity'], 2) ?>
                                            </td>
                                            <td>
                                                <small class="text-muted"><?= htmlspecialchars($adj['reason'] ?? 'Sin motivo') ?></small>
                                            </td>
                                            <td class="text-end">
                                                <button type="button" class="btn btn-outline-danger btn-sm" title="Eliminar registro" 
                                                        onclick="openDeleteModal(<?= $adj['id'] ?>, '<?= htmlspecialchars($adj['sku'] . ' - ' . $adj['product_name'], ENT_QUOTES) ?>', '<?= $adj['adjustment_type'] ?>', '<?= number_format($adj['quantity'], 2) ?>')">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">
                                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                            No hay ajustes registrados aún.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal de Confirmación para Eliminar Registro con opción de Revertir Stock -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark text-white border border-secondary shadow">
                <form action="ajustes.php" method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="adjustment_id" id="modal_adjustment_id">
                    
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title fw-bold" id="deleteModalLabel"><i class="bi bi-exclamation-triangle-fill text-danger me-2"></i>Eliminar Registro de Ajuste</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-secondary mb-2">Estás a punto de eliminar el registro del historial correspondiente a:</p>
                        <div class="p-2 bg-black border border-secondary rounded mb-3">
                            <strong id="modal_product_info" class="text-white d-block"></strong>
                            <span class="small text-muted">Tipo: <span id="modal_type" class="fw-bold"></span> | Cantidad: <span id="modal_qty" class="fw-bold"></span></span>
                        </div>
                        
                        <div class="form-check p-3 bg-warning-subtle text-dark border border-warning rounded">
                            <input class="form-check-input" type="checkbox" name="revert_stock" id="revert_stock" value="1" checked>
                            <label class="form-check-label fw-medium" for="revert_stock">
                                ¿Deseas revertir este movimiento en el inventario actual? 
                                <small class="d-block text-muted fw-normal">Si estaba restado, se sumará de vuelta al stock; si estaba sumado, se restará.</small>
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-danger btn-sm fw-semibold"><i class="bi bi-trash me-1"></i> Sí, eliminar registro</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openDeleteModal(id, productInfo, type, qty) {
            document.getElementById('modal_adjustment_id').value = id;
            document.getElementById('modal_product_info').innerText = productInfo;
            document.getElementById('modal_type').innerText = type;
            document.getElementById('modal_qty').innerText = qty;
            
            var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            deleteModal.show();
        }
    </script>
</body>
</html>