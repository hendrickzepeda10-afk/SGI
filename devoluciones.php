<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'config/database.php';

$mensaje = "";
$tipo_alerta = "";

$user_role = $_SESSION['role'] ?? '';
$current_username = $_SESSION['username'] ?? $_SESSION['user_nombre'] ?? 'Usuario';

try {
    $database = new Database();
    $pdo = $database->getConnection();

    // Consultar la lista de insumos/productos activos
    $stmtInsumos = $pdo->query("SELECT id, name AS nombre FROM products WHERE is_active = 1 ORDER BY name ASC");
    $listaInsumos = $stmtInsumos->fetchAll(PDO::FETCH_ASSOC);

    // Si es Administrador, consultamos todos los usuarios para el selector
    $listaUsuarios = [];
    if ($user_role === 'Administrador') {
        $stmtUsers = $pdo->query("SELECT username FROM users ORDER BY username ASC");
        $listaUsuarios = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $insumo_id   = $_POST['insumo_id'] ?? '';
        $cantidad    = $_POST['cantidad'] ?? '';
        
        // Si es admin toma lo del select, si no, asigna automáticamente el nombre del almacenista en sesión
        if ($user_role === 'Administrador') {
            $colaborador = trim($_POST['colaborador'] ?? '');
        } else {
            $colaborador = $current_username;
        }

        $departamento= trim($_POST['departamento'] ?? '');
        $motivo      = trim($_POST['motivo'] ?? '');
        $comentarios = trim($_POST['comentarios'] ?? '');
        $user_id     = $_SESSION['user_id'];

        if (!empty($insumo_id) && !empty($cantidad) && !empty($colaborador) && !empty($departamento) && !empty($motivo) && $cantidad > 0) {
            
            // Iniciamos una transacción para mantener la consistencia entre ambas tablas
            $pdo->beginTransaction();

            try {
                // 1. Insertar el registro de la devolución incluyendo colaborador y departamento
                $stmtInsert = $pdo->prepare("
                    INSERT INTO devoluciones (insumo_id, user_id, colaborador, departamento, cantidad, motivo, comentarios) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtInsert->execute([$insumo_id, $user_id, $colaborador, $departamento, $cantidad, $motivo, $comentarios]);

                // 2. Actualizar el stock actual usando 'current_stock' y 'product_id' en inventory_stock
                $stmtUpdateStock = $pdo->prepare("
                    UPDATE inventory_stock 
                    SET current_stock = current_stock + ? 
                    WHERE product_id = ?
                ");
                $stmtUpdateStock->execute([$cantidad, $insumo_id]);

                // Confirmar transacción
                $pdo->commit();

                $mensaje = "Devolución registrada y stock actualizado correctamente.";
                $tipo_alerta = "success";

            } catch (Exception $ex) {
                // Revertir cambios en caso de error
                $pdo->rollBack();
                $mensaje = "Error al procesar la devolución: " . $ex->getMessage();
                $tipo_alerta = "danger";
            }

        } else {
            $mensaje = "Por favor, completa los campos obligatorios y asegúrate de que la cantidad sea mayor a 0.";
            $tipo_alerta = "warning";
        }
    }
} catch (PDOException $e) {
    $mensaje = "Error de conexión: " . $e->getMessage();
    $tipo_alerta = "danger";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SGI | Devolución de Insumos</title>
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
            <h3 class="fw-bold text-white mb-1"><i class="bi bi-arrow-counterclockwise text-primary me-2"></i>Devolución de Insumos</h3>
            <p class="text-secondary small mb-0">Registra artículos devueltos por colaboradores o incidencias operativas.</p>
        </div>

        <?php if (!empty($mensaje)): ?>
            <div class="alert alert-<?= $tipo_alerta ?> alert-dismissible fade show border-0 shadow-sm" role="alert">
                <i class="bi bi-info-circle-fill me-2"></i><?= $mensaje ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="custom-card p-4">
            <form action="devoluciones.php" method="POST">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-secondary fw-medium">Seleccionar Insumo *</label>
                        <select name="insumo_id" class="form-select" required>
                            <option value="">-- Seleccione un insumo --</option>
                            <?php foreach ($listaInsumos as $insumo): ?>
                                <option value="<?= $insumo['id'] ?>"><?= htmlspecialchars($insumo['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label text-secondary fw-medium">Cantidad a devolver *</label>
                        <input type="number" name="cantidad" class="form-control" min="1" required placeholder="Ej. 2">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-secondary fw-medium">Nombre del Colaborador *</label>
                        <?php if ($user_role === 'Administrador'): ?>
                            <!-- Selector exclusivo para Administradores -->
                            <select name="colaborador" class="form-select" required>
                                <option value="">-- Seleccione un colaborador --</option>
                                <?php foreach ($listaUsuarios as $u): ?>
                                    <option value="<?= htmlspecialchars($u['username']) ?>"><?= htmlspecialchars($u['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <!-- Campo automático y bloqueado para Almacenistas u otros roles -->
                            <input type="text" name="colaborador" class="form-control bg-secondary text-white" value="<?= htmlspecialchars($current_username) ?>" readonly>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label text-secondary fw-medium">Departamento / Área *</label>
                        <input type="text" name="departamento" class="form-control" required placeholder="Ej. Informática, Contabilidad">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label text-secondary fw-medium">Motivo de la Devolución *</label>
                    <select name="motivo" class="form-select" required>
                        <option value="">-- Seleccione el motivo --</option>
                        <option value="Falla de fábrica">Falla de fábrica / Defectuoso</option>
                        <option value="Daño por traslado">Daño físico / Mal estado</option>
                        <option value="Error en pedido">Error en el tipo de insumo solicitado</option>
                        <option value="Otro">Otro motivo</option>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="form-label text-secondary fw-medium">Comentarios u observaciones adicionales</label>
                    <textarea name="comentarios" class="form-control" rows="3" placeholder="Detalles específicos del defecto..."></textarea>
                </div>

                <button type="submit" class="btn btn-primary fw-semibold py-2 px-4">
                    <i class="bi bi-check-circle-fill me-1"></i> Registrar Devolución
                </button>
            </form>
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