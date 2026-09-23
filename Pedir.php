<?php
// Pedir.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Incluir configuración de base de datos
require_once __DIR__ . '/config/database.php';

// Conexión unificada segura
$pdo = null;
if (isset($pdo_conn)) { $pdo =$pdo_conn; }
elseif (isset($conn)) { $pdo =$conn; }
elseif (isset($conexion)) { $pdo =$conexion; }
elseif (isset($db)) { $pdo =$db; }
elseif (class_exists('Database')) {
    $dbInstance = new Database();
    if (method_exists($dbInstance, 'getConnection')) {
        $pdo =$dbInstance->getConnection();
    } elseif (method_exists($dbInstance, 'connect')) {
        $pdo =$dbInstance->connect();
    }
}

// Obtener el nombre del usuario actual desde la base de datos o sesión
$nombre_usuario_actual =$_SESSION['username'] ?? 'Usuario';
$rol_actual =$_SESSION['role'] ?? '';

try {
    $stmtUser =$pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmtUser->execute([$_SESSION['user_id']]);
    $userData =$stmtUser->fetch(PDO::FETCH_ASSOC);
    if ($userData && !empty($userData['username'])) {
        $nombre_usuario_actual =$userData['username'];
    }
} catch (Exception $e) {
    // Fallback silencioso si ocurre algún inconveniente
}

// Obtener lista de usuarios por si el rol es Administrador
$lista_usuarios = [];
if ($rol_actual === 'Administrador') {
    try {
        $stmtUsersList =$pdo->query("SELECT id, username FROM users ORDER BY username ASC");
        $lista_usuarios =$stmtUsersList->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {$lista_usuarios = [];
    }
}

$mensaje = '';$tipoMensaje = '';

// Procesar el envío del formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($rol_actual === 'Administrador') {$solicitante = trim($_POST['solicitante_select'] ?? $nombre_usuario_actual);
    } else {
        $solicitante =$nombre_usuario_actual;
    }

    $departamento = trim($_POST['departamento'] ?? '');
    $product_id   = intval($_POST['product_id'] ?? 0);
    $cantidad     = intval($_POST['cantidad'] ?? 0);
    $prioridad    = trim($_POST['prioridad'] ?? 'Media');
    $motivo       = trim($_POST['motivo'] ?? '');

    $errorCampos = false;
    if (empty($solicitante)) {$errorCampos = true; }
    if (empty($departamento)) {$errorCampos = true; }
    if ($product_id <= 0) {$errorCampos = true; }
    if ($cantidad <= 0) {$errorCampos = true; }

    if ($errorCampos) {
        $mensaje = 'Por favor complete todos los campos obligatorios correctamente.';$tipoMensaje = 'danger';
    } else {
        try {
            $pdo->beginTransaction();

            $stmtStock =$pdo->prepare("SELECT current_stock FROM inventory_stock WHERE product_id = :product_id FOR UPDATE");
            $stmtStock->execute([':product_id' =>$product_id]);
            $stockData =$stmtStock->fetch(PDO::FETCH_ASSOC);

            $stockActual = $stockData ? floatval($stockData['current_stock']) : 0;

            if ($cantidad >$stockActual) {
                throw new Exception("Stock insuficiente. Solo quedan " . intval($stockActual) . " unidades disponibles de este insumo.");
            }

            $stmt =$pdo->prepare("INSERT INTO solicitudes (solicitante, departamento, product_id, cantidad, prioridad, motivo, estado) VALUES (?, ?, ?, ?, ?, ?, 'Pendiente')");
            $stmt->execute([$solicitante,$departamento, $product_id,$cantidad, $prioridad,$motivo]);

            $pdo->commit();$mensaje = '¡Solicitud registrada correctamente!';
            $tipoMensaje = 'success';

        } catch (Exception $e) {
            if ($pdo && $pdo->inTransaction()) {$pdo->rollBack();
            }
            $mensaje = 'Error al procesar la solicitud: ' . $e->getMessage();$tipoMensaje = 'danger';
        }
    }
}

try {
    $stmtProductos =$pdo->query("
        SELECT p.id, p.name, p.sku, COALESCE(i.current_stock, 0) AS stock 
        FROM products p 
        LEFT JOIN inventory_stock i ON p.id = i.product_id 
        ORDER BY p.name ASC
    ");
    $productos =$stmtProductos->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {$productos = [];
}

try {
    $stmtSolicitudes =$pdo->query("
        SELECT s.*, p.name AS producto_nombre, p.sku, 
               COALESCE(NULLIF(s.estado, ''), 'Pendiente') AS estado 
        FROM solicitudes s 
        LEFT JOIN products p ON s.product_id = p.id 
        ORDER BY s.fecha_solicitud DESC 
        LIMIT 10
    ");
    $solicitudes =$stmtSolicitudes->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {$solicitudes = [];
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
</head>
<body>

    <?php include './sidebar.php'; ?>

    <main class="main-content">
        <div class="mb-4">
            <h3 class="fw-bold text-white mb-1">Solicitud de Insumo</h3>
            <p class="text-secondary small">Registra requerimientos de material o suministros para los departamentos de la empresa.</p>
        </div>

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="custom-card">
                    <h5 class="fw-semibold text-white mb-3"><i class="bi bi-pencil-square text-primary me-2"></i>Nueva Solicitud</h5>
                    
                    <?php if (!empty($mensaje)): ?>
                        <div class="alert alert-<?= $tipoMensaje ?> alert-dismissible fade show text-start" role="alert">
                            <?= htmlspecialchars($mensaje) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="Pedir.php">
                        <div class="mb-3">
                            <label class="form-label text-secondary small">Solicitante</label>
                            <?php if ($rol_actual === 'Administrador'): ?>
                                <select name="solicitante_select" class="form-select" required>
                                    <option value="">-- Seleccionar solicitante --</option>
                                    <?php foreach ($lista_usuarios as$u): ?>
                                        <?php 
                                            $seleccionado = '';
                                            if ($u['username'] === $nombre_usuario_actual) {$seleccionado = 'selected';
                                            }
                                        ?>
                                        <option value="<?= htmlspecialchars($u['username']) ?>" <?= $seleccionado ?>>
                                            <?= htmlspecialchars($u['username']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="text" class="form-control bg-dark text-white border-secondary" value="<?= htmlspecialchars($nombre_usuario_actual) ?>" readonly>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-secondary small">Departamento / Área *</label>
                            <input type="text" name="departamento" class="form-control" placeholder="Ej: TI, Contabilidad" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-secondary small">Insumo Requerido *</label>
                            <select name="product_id" id="product_id" class="form-select" required onchange="actualizarStockMaximo()">
                                <option value="">-- Seleccionar producto --</option>
                                <?php foreach ($productos as$p): ?>
                                    <option value="<?= $p['id'] ?>" data-stock="<?= intval($p['stock']) ?>">
                                        <?= htmlspecialchars($p['name']) ?> (SKU: <?= htmlspecialchars($p['sku']) ?>) - Stock: <?= intval($p['stock']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="stock-aviso" class="form-text text-info small mt-1"></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-secondary small">Cantidad *</label>
                            <input type="number" name="cantidad" id="cantidad" class="form-control" min="1" placeholder="Ej: 10" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-secondary small">Prioridad</label>
                            <select name="prioridad" class="form-select">
                                <option value="Baja">Baja</option>
                                <option value="Media" selected>Media</option>
                                <option value="Alta">Alta</option>
                                <option value="Urgente">Urgente</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-secondary small">Motivo / Observación</label>
                            <textarea name="motivo" class="form-control" rows="3" placeholder="Observaciones adicionales..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 fw-semibold">
                            <i class="bi bi-send me-1"></i> Enviar Solicitud
                        </button>
                    </form>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="custom-card">
                    <h5 class="fw-semibold text-white mb-3"><i class="bi bi-clock-history text-info me-2"></i>Historial Reciente</h5>
                    <div class="table-responsive">
                        <table class="table table-dark-custom align-middle">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Solicitante</th>
                                    <th>Área</th>
                                    <th>Insumo</th>
                                    <th>Cant.</th>
                                    <th>Prioridad</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($solicitudes)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-secondary py-4">No se han registrado solicitudes aún.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($solicitudes as$s): ?>
                                        <tr>
                                            <td class="text-secondary small"><?= date('d/m/Y H:i', strtotime($s['fecha_solicitud'])) ?></td>
                                            <td class="fw-semibold"><?= htmlspecialchars($s['solicitante']) ?></td>
                                            <td><?= htmlspecialchars($s['departamento']) ?></td>
                                            <td>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle font-monospace mb-1"><?= htmlspecialchars($s['sku'] ?? 'N/A') ?></span><br>
                                                <?= htmlspecialchars($s['producto_nombre'] ?? 'Desconocido') ?>
                                            </td>
                                            <td class="fw-bold"><?= intval($s['cantidad']) ?></td>
                                            <td>
                                                <?php 
                                                    $prio = trim($s['prioridad']);$clasePrio = 'bg-secondary';
                                                    if ($prio === 'Urgente') {$clasePrio = 'bg-danger';
                                                    } elseif ($prio === 'Alta') {$clasePrio = 'bg-danger';
                                                    }
                                                ?>
                                                <span class="badge <?= $clasePrio ?>">
                                                    <?= htmlspecialchars($prio) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                    $est = trim($s['estado']);$claseEst = 'bg-secondary';
                                                    if ($est === 'Pendiente') {$claseEst = 'bg-warning text-dark';
                                                    } elseif ($est === 'En Proceso') {$claseEst = 'bg-info text-dark';
                                                    } elseif ($est === 'Entregado') {$claseEst = 'bg-success';
                                                    } elseif ($est === 'Completado') {$claseEst = 'bg-success';
                                                    } elseif ($est === 'Cancelado') {$claseEst = 'bg-danger';
                                                    }
                                                ?>
                                                <span class="badge badge-status <?= $claseEst ?>">
                                                    <?= htmlspecialchars($est) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div class="btn-logout-container">
        <a href="logout.php" class="btn btn-danger btn-sm rounded-pill px-3 shadow d-flex align-items-center gap-2">
            <i class="bi bi-box-arrow-right"></i> Cerrar Sesión
        </a>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function actualizarStockMaximo() {
            const select = document.getElementById('product_id');
            const selectedOption = select.options[select.selectedIndex];
            const stockRaw = selectedOption.getAttribute('data-stock');
            const stock = stockRaw ? parseInt(stockRaw, 10) : 0;
            const aviso = document.getElementById('stock-aviso');
            const inputCantidad = document.getElementById('cantidad');

            if (select.value !== "") {
                aviso.textContent = `Stock disponible en almacén: ${stock} unidades`;
                inputCantidad.setAttribute('max', stock);
            } else {
                aviso.textContent = '';
                inputCantidad.removeAttribute('max');
            }
        }
    </script>
</body>
</html>