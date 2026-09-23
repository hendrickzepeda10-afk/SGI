<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'config/database.php';

$pdo = null;
if (isset($pdo_conn)) { $pdo = $pdo_conn; }
elseif (isset($conn)) { $pdo = $conn; }
elseif (isset($conexion)) { $pdo = $conexion; }
elseif (isset($db)) { $pdo = $db; }
elseif (class_exists('Database')) {
    $dbInstance = new Database();
    if (method_exists($dbInstance, 'getConnection')) {
        $pdo = $dbInstance->getConnection();
    } elseif (method_exists($dbInstance, 'connect')) {
        $pdo = $dbInstance->connect();
    }
}

if ($pdo) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

$nombreUsuario = $_SESSION['username'] ?? $_SESSION['user_nombre'] ?? 'Usuario';
$mensajeError = "";
$mensajeExito = "";

// Procesar el cambio de estado de manera directa
if ($_SERVER['REQUEST_METHOD'] === 'POST' and isset($_POST['cambiar_estado'])) {
    $solicitud_id = intval($_POST['solicitud_id'] ?? 0);
    $nuevo_estado = trim($_POST['nuevo_estado'] ?? '');

    if ($solicitud_id > 0 and !empty($nuevo_estado)) {
        try {
            $pdo->beginTransaction();

            // 1. Obtener solicitud actual y bloquear la fila
            $stmtSelect = $pdo->prepare("SELECT product_id, cantidad, estado FROM solicitudes WHERE id = :id FOR UPDATE");
            $stmtSelect->execute([':id' => $solicitud_id]);
            $solicitud = $stmtSelect->fetch(PDO::FETCH_ASSOC);

            if (!$solicitud) {
                throw new Exception("La solicitud seleccionada no existe.");
            }

            $product_id = intval($solicitud['product_id']);
            $cantidad_solicitada = intval($solicitud['cantidad']);
            $estado_anterior = trim($solicitud['estado'] ?? 'Pendiente');

            // 2. Actualizar estado en la base de datos
            $stmtUpdate = $pdo->prepare("UPDATE solicitudes SET estado = :estado WHERE id = :id");
            $stmtUpdate->execute([
                ':estado' => $nuevo_estado,
                ':id' => $solicitud_id
            ]);

            // 3. Si pasa a Entregado o Completado y antes no lo estaba, descontar stock de inventory_stock
            $estadosFinales = ['Entregado', 'Completado'];
            $esFinalNuevo = in_array($nuevo_estado, $estadosFinales, true);
            $esFinalAntiguo = in_array($estado_anterior, $estadosFinales, true);

            if ($esFinalNuevo and !$esFinalAntiguo) {
                $stmtStock = $pdo->prepare("SELECT current_stock FROM inventory_stock WHERE product_id = :product_id");
                $stmtStock->execute([':product_id' => $product_id]);
                $stockData = $stmtStock->fetch(PDO::FETCH_ASSOC);

                $stockActual = $stockData ? floatval($stockData['current_stock']) : 0;

                if ($stockActual < $cantidad_solicitada) {
                    throw new Exception("Stock insuficiente en almacén. Stock disponible: " . intval($stockActual));
                }

                $stmtRestar = $pdo->prepare("UPDATE inventory_stock SET current_stock = current_stock - :cantidad WHERE product_id = :product_id");
                $stmtRestar->execute([
                    ':cantidad' => $cantidad_solicitada,
                    ':product_id' => $product_id
                ]);
            }

            $pdo->commit();
            $mensajeExito = "¡Estado actualizado correctamente a: " . htmlspecialchars($nuevo_estado) . "!";

        } catch (Exception $e) {
            if ($pdo and $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensajeError = "No se pudo actualizar el estado: " . $e->getMessage();
        }
    } else {
        $mensajeError = "Datos de solicitud inválidos.";
    }
}

// Consultar todas las solicitudes para mostrarlas en la tabla
try {
    $stmtReg = $pdo->query("
        SELECT s.id, p.name AS insumo, s.cantidad, s.motivo, s.fecha_solicitud, s.solicitante, s.departamento, s.prioridad, 
               COALESCE(NULLIF(s.estado, ''), 'Pendiente') AS estado 
        FROM solicitudes s
        INNER JOIN products p ON s.product_id = p.id
        ORDER BY s.fecha_solicitud DESC
    ");
    $registros = $stmtReg->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $registros = [];
    $mensajeError = "Error al cargar las solicitudes: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SGI | Gestión de Solicitudes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/estilo.css">
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="position-absolute top-0 end-0 p-4" style="z-index: 1050;">
        <div class="session-badge px-3 py-2 rounded-pill d-flex align-items-center gap-2 shadow-sm">
            <span class="bg-success rounded-circle" style="width: 8px; height: 8px; display: inline-block;"></span>
            <span>SESIÓN: <strong><?= htmlspecialchars($nombreUsuario) ?></strong></span>
        </div>
    </div>

    <main class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold text-white mb-1"><i class="bi bi-clock-history text-primary me-2"></i>Gestión de Solicitudes</h2>
                <p class="text-secondary small mb-0">Visualiza las solicitudes y actualiza su estado de forma rápida.</p>
            </div>
        </div>

        <?php if (!empty($mensajeError)): ?>
            <div class="alert alert-danger border-0 bg-dark text-danger shadow-sm mb-4">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($mensajeError) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($mensajeExito)): ?>
            <div class="alert alert-success border-0 bg-dark text-success shadow-sm mb-4">
                <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($mensajeExito) ?>
            </div>
        <?php endif; ?>

        <div class="custom-card">
            <div class="table-responsive">
                <table class="table table-dark-custom align-middle mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Insumo</th>
                            <th>Cant.</th>
                            <th>Depto.</th>
                            <th>Prioridad</th>
                            <th>Motivo</th>
                            <th>Solicitante</th>
                            <th>Estado Actual</th>
                            <th>Fecha y Hora</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($registros)): ?>
                            <?php foreach ($registros as $row): ?>
                                <tr>
                                    <td class="fw-bold text-secondary">#<?= $row['id'] ?></td>
                                    <td><strong class="text-white"><?= htmlspecialchars($row['insumo']) ?></strong></td>
                                    <td><span class="badge bg-secondary"><?= intval($row['cantidad']) ?></span></td>
                                    <td><?= htmlspecialchars($row['departamento']) ?></td>
                                    <td>
                                        <span class="badge <?= ($row['prioridad'] === 'Alta' or $row['prioridad'] === 'Urgente') ? 'bg-danger' : 'bg-info text-dark' ?>">
                                            <?= htmlspecialchars($row['prioridad']) ?>
                                        </span>
                                    </td>
                                    <td><span class="badge badge-motivo px-2 py-1"><?= htmlspecialchars($row['motivo']) ?></span></td>
                                    <td><i class="bi bi-person-circle me-1 text-muted"></i><?= htmlspecialchars($row['solicitante']) ?></td>
                                    <td>
                                        <span class="badge 
                                            <?php 
                                                $est = trim($row['estado']);
                                                if ($est == 'Pendiente') echo 'bg-warning text-dark';
                                                elseif ($est == 'En Proceso') echo 'bg-info text-dark';
                                                elseif ($est == 'Entregado' or $est == 'Completado') echo 'bg-success';
                                                elseif ($est == 'Cancelado') echo 'bg-danger';
                                                else echo 'bg-secondary';
                                            ?>">
                                            <?= htmlspecialchars($row['estado']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-1 text-info small">
                                            <i class="bi bi-calendar3"></i>
                                            <span><?= date('d/m/Y', strtotime($row['fecha_solicitud'])) ?></span>
                                            <i class="bi bi-clock ms-1 text-secondary"></i>
                                            <span class="text-secondary"><?= date('H:i:s', strtotime($row['fecha_solicitud'])) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <form method="POST" action="ver_solicitudes.php" class="d-flex gap-1 align-items-center">
                                            <input type="hidden" name="solicitud_id" value="<?= $row['id'] ?>">
                                            <select name="nuevo_estado" class="form-select form-select-sm bg-dark text-white border-secondary" style="font-size: 0.75rem; width: 115px;">
                                                <option value="Pendiente" <?= $row['estado'] === 'Pendiente' ? 'selected' : '' ?>>Pendiente</option>
                                                <option value="En Proceso" <?= $row['estado'] === 'En Proceso' ? 'selected' : '' ?>>En Proceso</option>
                                                <option value="Entregado" <?= $row['estado'] === 'Entregado' ? 'selected' : '' ?>>Entregado</option>
                                                <option value="Completado" <?= $row['estado'] === 'Completado' ? 'selected' : '' ?>>Completado</option>
                                                <option value="Cancelado" <?= $row['estado'] === 'Cancelado' ? 'selected' : '' ?>>Cancelado</option>
                                            </select>
                                            <button type="submit" name="cambiar_estado" class="btn btn-sm btn-outline-primary" title="Actualizar estado">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center py-5 text-secondary">
                                    <i class="bi bi-folder2-open fs-2 d-block mb-2"></i>
                                    No hay solicitudes registradas en este momento.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>