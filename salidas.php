<?php
// Activar reporte de errores para depuración
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Validar que el usuario esté autenticado y capturar su ID de forma segura
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'config/database.php';

$database = new Database();
$pdo =$database->getConnection();

$mensaje = "";
$error = "";

$DEFAULT_LOCATION_ID = 1;
$current_user_id =$_SESSION['user_id']; // ID del usuario autenticado actualmente en sesión

// Procesar Acciones POST (Crear o Eliminar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action =$_POST['action'] ?? 'crear';

    // ELIMINAR SALIDA Y DEVOLVER STOCK
    if ($action === 'eliminar') {
        $movement_id = (int)($_POST['movement_id'] ?? 0);
        if ($movement_id > 0) {
            try {
                $pdo->beginTransaction();

                // 1. Obtener los datos del movimiento de salida antes de borrarlo
                $stmtGet =$pdo->prepare("SELECT product_id, source_location_id, quantity FROM inventory_movements WHERE id = ? AND movement_type = 'SALIDA'");
                $stmtGet->execute([$movement_id]);
                $movData =$stmtGet->fetch();

                if (!$movData) {
                    throw new Exception("No se encontró el registro de salida especificado.");
                }

                $prod_id = $movData['product_id'];$loc_id  = $movData['source_location_id'] ?? $DEFAULT_LOCATION_ID;
                $cant    = (float)$movData['quantity'];

                // 2. Devolver el stock al almacén de donde salió
                $stmtCheckStock =$pdo->prepare("SELECT id FROM inventory_stock WHERE product_id = ? AND location_id = ?");
                $stmtCheckStock->execute([$prod_id,$loc_id]);
                
                if ($stmtCheckStock->fetch()) {
                    $stmtUpStock =$pdo->prepare("UPDATE inventory_stock SET current_stock = current_stock + ? WHERE product_id = ? AND location_id = ?");
                    $stmtUpStock->execute([$cant, $prod_id,$loc_id]);
                } else {
                    $stmtInsStock =$pdo->prepare("INSERT INTO inventory_stock (product_id, location_id, current_stock) VALUES (?, ?, ?)");
                    $stmtInsStock->execute([$prod_id, $loc_id,$cant]);
                }

                // 3. Eliminar el movimiento
                $stmtDel =$pdo->prepare("DELETE FROM inventory_movements WHERE id = ?");
                $stmtDel->execute([$movement_id]);

                $pdo->commit();$mensaje = "Salida eliminada correctamente y stock devuelto al inventario.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {$pdo->rollBack();
                }
                $error = "<strong>Error al eliminar la salida:</strong> " . htmlspecialchars($e->getMessage());
            }
        }
    } 
    // REGISTRAR NUEVA SALIDA / DISTRIBUCIÓN
    else {
        $product_id         = (int)($_POST['product_id'] ?? 0);$quantity           = (float)($_POST['quantity'] ?? 0);$source_location_id = (int)($_POST['location_id'] ?? $DEFAULT_LOCATION_ID);
        $reason             = trim($_POST['reason'] ?? '');

        if ($product_id > 0 && $quantity > 0 &&$source_location_id > 0) {
            try {
                $pdo->beginTransaction();

                // 1. Consultar únicamente el stock del almacén seleccionado con bloqueo de concurrencia
                $stmtStock =$pdo->prepare("SELECT current_stock FROM inventory_stock WHERE product_id = ? AND location_id = ? FOR UPDATE");
                $stmtStock->execute([$product_id,$source_location_id]);
                $stockData =$stmtStock->fetch();

                $currentStockInLocation = $stockData ? (float)$stockData['current_stock'] : 0;

                // 2. Validar que la cantidad solicitada exista en dicho almacén
                if ($quantity >$currentStockInLocation) {
                    throw new Exception("Stock insuficiente en el almacén seleccionado. Disponible en esta ubicación: " . number_format($currentStockInLocation, 2));
                }

                // 3. Descontar stock del almacén de origen
                $stmtUpdateStock =$pdo->prepare("UPDATE inventory_stock SET current_stock = current_stock - ? WHERE product_id = ? AND location_id = ?");
                $stmtUpdateStock->execute([$quantity, $product_id,$source_location_id]);

                // 4. Registrar la salida en el historial asegurando guardar el $current_user_id
                $stmtMove =$pdo->prepare("
                    INSERT INTO inventory_movements 
                    (product_id, source_location_id, target_location_id, movement_type, quantity, reason, user_id) 
                    VALUES (?, ?, NULL, 'SALIDA', ?, ?, ?)
                ");
                $stmtMove->execute([$product_id, $source_location_id,$quantity, $reason,$current_user_id]);

                $pdo->commit();$mensaje = "Salida / Distribución registrada correctamente.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {$pdo->rollBack();
                }
                $error = "<strong>Error al procesar salida:</strong> " . htmlspecialchars($e->getMessage());
            }
        } else {
            $error = "<strong>Faltan campos obligatorios:</strong> Selecciona un insumo, un almacén válido y una cantidad mayor a 0.";
        }
    }
}

// Consultar Productos Activos y su stock agrupado por almacén
$products = [];
try {
    $stmtProd =$pdo->query("SELECT p.id, p.sku, p.name, p.unit_of_measure, 
                            COALESCE(SUM(s.current_stock), 0) as stock_total 
                            FROM products p 
                            LEFT JOIN inventory_stock s ON p.id = s.product_id 
                            WHERE p.is_active = 1 
                            GROUP BY p.id 
                            ORDER BY p.name ASC");
    if ($stmtProd) {
        $products =$stmtProd->fetchAll();
    }
} catch (PDOException $e) {$error = "Error al cargar productos: " . htmlspecialchars($e->getMessage());
}

// Consultar Stock desglosado por producto y ubicación
$stockByLocation = [];
try {
    $stmtStockMap =$pdo->query("SELECT product_id, location_id, current_stock FROM inventory_stock");
    while ($row =$stmtStockMap->fetch(PDO::FETCH_ASSOC)) {
        $stockByLocation[$row['product_id']][$row['location_id']] = (float)$row['current_stock'];
    }
} catch (PDOException $e) {}

// Consultar Ubicaciones
$locations = [];
try {
    $stmtLoc =$pdo->query("SELECT * FROM locations ORDER BY name ASC");
    if ($stmtLoc) {
        $locations =$stmtLoc->fetchAll();
    }
} catch (PDOException $e) {}

// Consultar Historial de Salidas (Obteniendo el 'username' y 'full_name' de la tabla users)
$salidas = [];
try {
    $querySalidas = "SELECT m.*, p.sku, p.name as product_name, p.unit_of_measure, l.name as location_name, u.username, u.full_name 
                     FROM inventory_movements m 
                     INNER JOIN products p ON m.product_id = p.id 
                     LEFT JOIN locations l ON m.source_location_id = l.id 
                     LEFT JOIN users u ON m.user_id = u.id 
                     WHERE m.movement_type = 'SALIDA' 
                     ORDER BY m.id DESC LIMIT 50";
    $stmtSal = $pdo->query($querySalidas);
    if ($stmtSal) {
        $salidas =$stmtSal->fetchAll();
    }
} catch (PDOException $e) {$error = "Error al cargar el historial de salidas: " . htmlspecialchars($e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SGI | Solicitud de Insumo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/estilo.css">
    <link rel="stylesheet" href="css/temas.css">
</head>
<body>

  <?php include './sidebar.php'; ?>

    <main class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold text-white mb-1">Distribución y Salidas de Insumos</h3>
                <p class="text-muted mb-0">Registra entregas de material a departamentos, proyectos o personal</p>
            </div>
            <div>
                <button class="btn btn-danger px-3 py-2 fw-medium shadow-sm" data-bs-toggle="modal" data-bs-target="#modalSalida">
                    <i class="bi bi-plus-lg me-1"></i> Nueva Salida
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

        <!-- Historial de Salidas -->
        <div class="custom-card">
            <h5 class="fw-bold text-white mb-3">Historial de Salidas Recientes</h5>
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle mb-0 table-dark-custom salidas-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>SKU / INSUMO</th>
                            <th>CANTIDAD</th>
                            <th>ALMACÉN ORIGEN</th>
                            <th>MOTIVO / OBSERVACIÓN</th>
                            <th>USUARIO</th>
                            <th>FECHA</th>
                            <th class="text-center">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($salidas) > 0): ?>
                            <?php foreach ($salidas as$sal): ?>
                                <tr>
                                    <td><span class="text-muted">#<?= $sal['id'] ?></span></td>
                                    <td>
                                        <div class="fw-bold text-white"><code><?= htmlspecialchars($sal['sku']) ?></code></div>
                                        <small class="text-muted"><?= htmlspecialchars($sal['product_name']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-2 py-1">
                                            -<?= number_format($sal['quantity'], 2) ?> <?= htmlspecialchars($sal['unit_of_measure'] ?? 'Unid.') ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($sal['location_name'] ?? 'Almacén Principal') ?></td>
                                    <td><?= htmlspecialchars($sal['reason'] ?? 'N/A') ?></td>
                                    <td>
                                        <?php 
                                            // Mostrar el username (ej: halvarez, Operador) o el full_name si existe, de lo contrario Desconocido
                                            if (!empty($sal['username'])) {
                                                echo '<span class="badge bg-secondary bg-opacity-25 text-info border border-secondary px-2 py-1"><i class="bi bi-person-fill me-1"></i>' . htmlspecialchars($sal['username']) . '</span>';
                                            } else {
                                                echo '<span class="text-muted fst-italic">Sin usuario</span>';
                                            }
                                        ?>
                                    </td>
                                    <td><small class="text-muted"><?= $sal['created_at'] ?></small></td>
                                    <td class="text-center">
                                        <form action="salidas.php" method="POST" onsubmit="return confirm('¿Estás seguro de eliminar esta salida? Esta acción devolverá el stock al inventario.');" style="display:inline-block;">
                                            <input type="hidden" name="action" value="eliminar">
                                            <input type="hidden" name="movement_id" value="<?= $sal['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Eliminar Salida y Revertir Stock">
                                                <i class="bi bi-trash-fill"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    <i class="bi bi-arrow-up-right-square fs-3 d-block mb-2"></i>
                                    No hay salidas registradas aún. Haz clic en <strong>"Nueva Salida"</strong>.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modal Nueva Salida -->
    <div class="modal fade" id="modalSalida" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content custom-card border shadow-lg">
                <form action="salidas.php" method="POST">
                    <input type="hidden" name="action" value="crear">
                    <div class="modal-header border-bottom border-secondary">
                        <h5 class="modal-title fw-bold text-white">Registrar Nueva Salida</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label text-secondary fw-medium">Insumo / Producto *</label>
                            <select name="product_id" id="product_id" class="form-select" required onchange="actualizarStockUbicacion()">
                                <option value="">-- Seleccionar Insumo --</option>
                                <?php foreach ($products as$prod): ?>
                                    <option value="<?= $prod['id'] ?>">
                                        <?= htmlspecialchars($prod['sku']) ?> - <?= htmlspecialchars($prod['name']) ?> (Total: <?= number_format($prod['stock_total'], 2) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text text-muted" id="infoStock">Selecciona un producto y almacén para ver la disponibilidad.</div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label text-secondary fw-medium">Almacén de Origen *</label>
                                <select name="location_id" id="location_id" class="form-select" required onchange="actualizarStockUbicacion()">
                                    <option value="">-- Seleccionar Almacén --</option>
                                    <?php foreach ($locations as$loc): ?>
                                        <option value="<?= $loc['id'] ?>"><?= htmlspecialchars($loc['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-secondary fw-medium">Cantidad a Retirar *</label>
                                <input type="number" step="0.01" name="quantity" id="quantity" class="form-control" required min="0.01" placeholder="Ej: 5">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-secondary fw-medium">Motivo u Observación</label>
                            <textarea name="reason" class="form-control" rows="2" placeholder="Ej: Entrega a Departamento / Mantenimiento"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-top border-secondary">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-danger px-4">Confirmar Salida</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const stockDataMap = <?= json_encode($stockByLocation); ?>;

        function actualizarStockUbicacion() {
            const productId = document.getElementById('product_id').value;
            const locationId = document.getElementById('location_id').value;
            const infoStock = document.getElementById('infoStock');
            const quantityInput = document.getElementById('quantity');

            if (productId && locationId) {
                const stockDisponible = (stockDataMap[productId] && stockDataMap[productId][locationId]) 
                    ? Number(stockDataMap[productId][locationId]) 
                    : 0;

                infoStock.innerText = `Stock disponible en esta ubicación: ${stockDisponible.toFixed(2)}`;
                quantityInput.max = stockDisponible;
            } else {
                infoStock.innerText = 'Selecciona un producto y almacén para ver la disponibilidad.';
                quantityInput.removeAttribute('max');
            }
        }
    </script>
</body>
</html>