<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once 'config/database.php';

$database = new Database();
$pdo = $database->getConnection();

$mensaje = "";
$error = "";

$DEFAULT_LOCATION_ID = 1;

// Obtener columnas reales
$movColumns = $database->getTableColumns('inventory_movements');
$stockColumns = $database->getTableColumns('inventory_stock');

// Encontrar qué columna se usa para el tipo de movimiento
$actualTypeCol = 'movement_type';
foreach (['movement_type', 'type', 'tipo', 'movement', 'ingress_type'] as $col) {
    if (in_array($col, $movColumns)) {
        $actualTypeCol = $col;
        break;
    }
}

// Asegurar que la columna sea un VARCHAR amplio para evitar restricciones de tipo
try {
    $pdo->exec("ALTER TABLE inventory_movements MODIFY COLUMN $actualTypeCol VARCHAR(100) DEFAULT 'COMPRA_DIRECTA'");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE inventory_movements ADD COLUMN $actualTypeCol VARCHAR(100) DEFAULT 'COMPRA_DIRECTA'");
    } catch (Exception $ex) {}
}

// Auto-crear o asegurar columnas necesarias adicionales
try {
    if (!in_array('supplier', $movColumns)) {
        $pdo->exec("ALTER TABLE inventory_movements ADD COLUMN supplier VARCHAR(255) DEFAULT NULL");
    }
    if (!in_array('reference_no', $movColumns)) {
        $pdo->exec("ALTER TABLE inventory_movements ADD COLUMN reference_no VARCHAR(100) DEFAULT NULL");
    }
    if (!in_array('notes', $movColumns)) {
        $pdo->exec("ALTER TABLE inventory_movements ADD COLUMN notes TEXT DEFAULT NULL");
    }
} catch (Exception $e) {}

// Recargar columnas
$movColumns = $database->getTableColumns('inventory_movements');

// Función auxiliar para obtener un usuario válido
function getValidUserId($pdo) {
    if (!empty($_SESSION['user_id'])) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id = ?");
            $stmt->execute([(int)$_SESSION['user_id']]);
            $validUser = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($validUser) {
                return (int)$validUser['id'];
            }
        } catch (Exception $e) {}
    }

    try {
        $stmt = $pdo->query("SELECT id FROM usuarios ORDER BY id ASC LIMIT 1");
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $_SESSION['user_id'] = (int)$user['id'];
            return (int)$user['id'];
        }
    } catch (Exception $e) {}

    try {
        $stmtInsert = $pdo->prepare("INSERT INTO usuarios (rol_id, nombre, email, password) VALUES (?, ?, ?, ?)");
        $stmtInsert->execute([1, 'Admin', 'admin@sistema.local', password_hash('123456', PASSWORD_DEFAULT)]);
        $newId = (int)$pdo->lastInsertId();
        $_SESSION['user_id'] = $newId;
        return $newId;
    } catch (Exception $e) {
        return 1;
    }
}

// 1. ELIMINAR MOVIMIENTO
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $delete_id = (int)$_GET['delete'];
    try {
        $stmtDel = $pdo->prepare("DELETE FROM inventory_movements WHERE id = ?");
        $stmtDel->execute([$delete_id]);
        header("Location: entradas.php?deleted=1");
        exit;
    } catch (PDOException $e) {
        $error = "Error al eliminar: " . $e->getMessage();
    }
}

if (isset($_GET['deleted']) && $_GET['deleted'] == 1) {
    $mensaje = "El registro fue eliminado correctamente.";
}

// 2. PROCESAR FORMULARIO DE REABASTECIMIENTO (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id     = (int)($_POST['product_id'] ?? 0);
    $quantity       = (float)($_POST['quantity'] ?? 0);
    $movement_type = trim($_POST['movement_type'] ?? 'COMPRA_DIRECTA');
    $supplier       = trim($_POST['supplier'] ?? '');
    $reference_no  = trim($_POST['reference_no'] ?? '');
    $notes          = trim($_POST['notes'] ?? '');

    if (empty($movement_type)) {
        $movement_type = 'COMPRA_DIRECTA';
    }

    if ($product_id > 0 && $quantity > 0) {
        try {
            $pdo->beginTransaction();

            $validUserId = getValidUserId($pdo);

            try {
                $pdo->exec("INSERT IGNORE INTO locations (id, name, type) VALUES ($DEFAULT_LOCATION_ID, 'Almacén Central', 'WAREHOUSE')");
            } catch (Exception $e) {}

            $fields = ['product_id', 'quantity'];
            $params = [$product_id, $quantity];

            if (in_array('user_id', $movColumns)) {
                $fields[] = 'user_id';
                $params[] = $validUserId;
            }

            $fields[] = $actualTypeCol;
            $params[] = $movement_type;

            if (in_array('destination_location_id', $movColumns)) {
                $fields[] = 'destination_location_id';
                $params[] = $DEFAULT_LOCATION_ID;
            } elseif (in_array('location_id', $movColumns)) {
                $fields[] = 'location_id';
                $params[] = $DEFAULT_LOCATION_ID;
            }

            if (in_array('supplier', $movColumns)) {
                $fields[] = 'supplier';
                $params[] = $supplier;
            }

            if (in_array('reference_no', $movColumns)) {
                $fields[] = 'reference_no';
                $params[] = $reference_no;
            }

            if (in_array('notes', $movColumns)) {
                $fields[] = 'notes';
                $params[] = $notes;
            }

            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $sqlMov = "INSERT INTO inventory_movements (" . implode(', ', $fields) . ") VALUES ($placeholders)";
            
            $stmtMov = $pdo->prepare($sqlMov);
            $stmtMov->execute($params);

            if (in_array('location_id', $stockColumns)) {
                $stmtStock = $pdo->prepare("INSERT INTO inventory_stock (product_id, location_id, current_stock) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE current_stock = current_stock + VALUES(current_stock)");
                $stmtStock->execute([$product_id, $DEFAULT_LOCATION_ID, $quantity]);
            } else {
                $stmtStock = $pdo->prepare("INSERT INTO inventory_stock (product_id, current_stock) 
                    VALUES (?, ?) 
                    ON DUPLICATE KEY UPDATE current_stock = current_stock + VALUES(current_stock)");
                $stmtStock->execute([$product_id, $quantity]);
            }

            $pdo->commit();
            
            header("Location: entradas.php?success=1");
            exit;

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "<strong>Error de MySQL:</strong> " . $e->getMessage();
        }
    } else {
        $error = "Por favor selecciona un insumo y especifica una cantidad válida.";
    }
}

if (isset($_GET['success']) && $_GET['success'] == 1) {
    $mensaje = "Reabastecimiento registrado exitosamente.";
}

// Consultar Insumos
$products = [];
try {
    $stmtProd = $pdo->query("SELECT id, sku, name, unit_of_measure FROM products WHERE is_active = 1 ORDER BY name ASC");
    if ($stmtProd) $products = $stmtProd->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Consultar Historial
$movements = [];
try {
    $selectType = in_array($actualTypeCol, $movColumns) ? "m.$actualTypeCol" : "'COMPRA_DIRECTA'";
    $selectSupplier = in_array('supplier', $movColumns) ? "m.supplier" : "'-'";
    $selectRef = in_array('reference_no', $movColumns) ? "m.reference_no" : "'-'";
    $selectDate = in_array('created_at', $movColumns) ? "m.created_at" : "NOW()";

    $queryMov = "SELECT m.id, m.product_id, m.quantity, p.sku, p.name as product_name, p.unit_of_measure,
                        COALESCE($selectDate, NOW()) as created_at,
                        COALESCE($selectType, 'COMPRA_DIRECTA') as movement_type,
                        COALESCE($selectRef, '-') as reference_no,
                        COALESCE($selectSupplier, '-') as supplier
                 FROM inventory_movements m 
                 JOIN products p ON m.product_id = p.id 
                 ORDER BY m.id DESC LIMIT 50";
                 
    $stmtMovList = $pdo->query($queryMov);
    if ($stmtMovList) $movements = $stmtMovList->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Error al consultar historial: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SGI | Recepción y Reabastecimiento</title>
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
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold text-white mb-1">Recepción y Reabastecimiento</h3>
                <p class="text-secondary mb-0">Ingreso de insumos al Almacén Central para posterior asignación y distribución interna</p>
            </div>
            <div>
                <button class="btn btn-success px-3 py-2 fw-medium d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#modalEntrada">
                    <i class="bi bi-plus-lg"></i> Registrar Reabastecimiento
                </button>
            </div>
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

        <!-- Historial de Entradas -->
        <div class="custom-card p-4">
            <h6 class="fw-bold mb-3 text-secondary"><i class="bi bi-clock-history me-1"></i> Historial Reciente de Reabastecimientos</h6>
            <div class="table-responsive">
                <table class="table table-dark-custom align-middle mb-0">
                    <thead>
                        <tr>
                            <th>FECHA / HORA</th>
                            <th>SKU</th>
                            <th>INSUMO</th>
                            <th>TIPO DE INGRESO</th>
                            <th>CANTIDAD</th>
                            <th>PROVEEDOR</th>
                            <th>N° ORDEN</th>
                            <th class="text-end">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($movements) > 0): ?>
                            <?php foreach ($movements as $mov): ?>
                                <tr>
                                    <td class="text-nowrap" style="font-size: 0.85rem;">
                                        <?= !empty($mov['created_at']) ? date('d/m/Y H:i', strtotime($mov['created_at'])) : '-' ?>
                                    </td>
                                    <td><code><?= htmlspecialchars($mov['sku'] ?? '') ?></code></td>
                                    <td class="fw-semibold text-white"><?= htmlspecialchars($mov['product_name'] ?? '') ?></td>
                                    <td>
                                        <?php 
                                            $t = trim($mov['movement_type']);
                                            if (empty($t)) $t = 'COMPRA_DIRECTA';
                                            
                                            $badgeClass = "bg-success bg-opacity-25 text-success border border-success";
                                            if ($t === 'ALMACEN_CENTRAL') $badgeClass = "bg-primary bg-opacity-25 text-primary border border-primary";
                                            elseif ($t === 'DONACION') $badgeClass = "bg-info bg-opacity-25 text-info border border-info";
                                            elseif ($t === 'DEVOLUCION_COLABORADOR') $badgeClass = "bg-warning bg-opacity-25 text-warning border border-warning";
                                            elseif ($t === 'INVENTARIO_INICIAL') $badgeClass = "bg-secondary bg-opacity-25 text-secondary border border-secondary";
                                        ?>
                                        <span class="badge <?= $badgeClass ?> px-2 py-1">
                                            <?= htmlspecialchars($t) ?>
                                        </span>
                                    </td>
                                    <td class="fw-bold text-success">
                                        +<?= number_format($mov['quantity'] ?? 0, 2) ?> <?= htmlspecialchars($mov['unit_of_measure'] ?? '') ?>
                                    </td>
                                    <td><?= htmlspecialchars($mov['supplier'] !== '-' && $mov['supplier'] !== '' ? $mov['supplier'] : '-') ?></td>
                                    <td>
                                        <small class="text-secondary"><?= htmlspecialchars($mov['reference_no'] !== '-' && $mov['reference_no'] !== '' ? $mov['reference_no'] : '-') ?></small>
                                    </td>
                                    <td class="text-end">
                                        <a href="entradas.php?delete=<?= $mov['id'] ?>" class="btn btn-outline-danger btn-sm" onclick="return confirm('¿Estás seguro de eliminar este registro?');" title="Eliminar registro">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-secondary">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                    No hay reabastecimientos registrados.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modal Registrar Reabastecimiento -->
    <div class="modal fade" id="modalEntrada" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow custom-card p-0">
                <form action="entradas.php" method="POST">
                    <div class="modal-header border-bottom border-secondary">
                        <h5 class="modal-title fw-bold text-white">Registrar Reabastecimiento Interno</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label text-secondary fw-medium">Insumo a Reabastecer *</label>
                            <select name="product_id" class="form-select" required>
                                <option value="">-- Seleccionar Insumo --</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= $p['id'] ?>">
                                        [<?= htmlspecialchars($p['sku']) ?>] <?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['unit_of_measure']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label text-secondary fw-medium">Cantidad a Ingresar *</label>
                                <input type="number" step="0.01" min="0.01" name="quantity" class="form-control" required placeholder="0.00">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-secondary fw-medium">Tipo de Reabastecimiento</label>
                                <select name="movement_type" class="form-select">
                                    <option value="COMPRA_DIRECTA">Compra Directa</option>
                                    <option value="ALMACEN_CENTRAL">Envío Almacén Central</option>
                                    <option value="DEVOLUCION_COLABORADOR">Devolución de Colaborador</option>
                                    <option value="INVENTARIO_INICIAL">Carga Inicial</option>
                                </select>
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label text-secondary fw-medium">Proveedor / Origen</label>
                                <input type="text" name="supplier" class="form-control" placeholder="Distribuidora...">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-secondary fw-medium">N° Factura / Orden Compra</label>
                                <input type="text" name="reference_no" class="form-control" placeholder="XX-XX-XXXX">
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label text-secondary fw-medium">Notas / Observaciones</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Información relevante..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-top border-secondary">
                        <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success px-4">Ingresar a Inventario</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Botón de Cerrar Sesión flotante -->
    <div class="btn-logout-container">
        <a href="logout.php" class="btn btn-danger px-4 py-2 shadow-lg fw-medium d-flex align-items-center gap-2">
            <i class="bi bi-box-arrow-right"></i> Cerrar Sesión
        </a>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>