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
$registros = [];

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';

try {
    $database = new Database();
    $pdo = $database->getConnection();

    // Procesar la eliminación si se envió una solicitud POST con el ID de devolución
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_id'])) {
        // Solo permitiremos eliminar a Administradores (puedes ajustar los roles aquí si el almacenista también puede)
        if (in_array($user_role, ['Administrador'])) {
            $id_a_eliminar = intval($_POST['eliminar_id']);

            $stmtDel = $pdo->prepare("DELETE FROM devoluciones WHERE id = ?");
            if ($stmtDel->execute([$id_a_eliminar])) {
                $mensaje = "El registro de devolución #$id_a_eliminar ha sido eliminado correctamente.";
                $tipo_alerta = "success";
            } else {
                $mensaje = "No se pudo eliminar el registro.";
                $tipo_alerta = "danger";
            }
        } else {
            $mensaje = "No tienes permisos suficientes para eliminar registros.";
            $tipo_alerta = "warning";
        }
    }

    // Consulta de registros según el rol
    if (in_array($user_role, ['Administrador', 'Almacenista'])) {
        $sql = "SELECT d.id, p.name AS insumo_nombre, d.cantidad, d.colaborador, d.departamento, d.motivo, d.comentarios, d.fecha_registro, u.username AS usuario_registra 
                FROM devoluciones d
                LEFT JOIN products p ON d.insumo_id = p.id
                LEFT JOIN users u ON d.user_id = u.id
                ORDER BY d.fecha_registro DESC";
        $stmt = $pdo->query($sql);
        $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $sql = "SELECT d.id, p.name AS insumo_nombre, d.cantidad, d.colaborador, d.departamento, d.motivo, d.comentarios, d.fecha_registro, u.username AS usuario_registra 
                FROM devoluciones d
                LEFT JOIN products p ON d.insumo_id = p.id
                LEFT JOIN users u ON d.user_id = u.id
                WHERE d.user_id = ?
                ORDER BY d.fecha_registro DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id]);
        $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    $mensaje = "Error en la base de datos: " . $e->getMessage();
    $tipo_alerta = "danger";
}

$nombreUsuario = $_SESSION['username'] ?? $_SESSION['user_nombre'] ?? 'Usuario';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SGI | Historial de Devoluciones</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/estilo.css">
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <main class="main-content">
        <!-- Encabezado superior -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <h2 class="fw-bold text-white mb-1"><i class="bi bi-journal-text text-primary me-2"></i>Historial de Devoluciones</h2>
                <p class="text-secondary small mb-0">
                    <?php if (in_array($user_role, ['Administrador', 'Almacenista'])): ?>
                        Registro general de todas las devoluciones realizadas en el sistema.
                    <?php else: ?>
                        Tus registros personales de devoluciones de insumos realizadas.
                    <?php endif; ?>
                </p>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <div class="session-badge px-3 py-2 rounded-pill d-flex align-items-center gap-2 shadow-sm bg-dark border border-secondary">
                    <span class="bg-success rounded-circle" style="width: 8px; height: 8px; display: inline-block;"></span>
                    <span class="text-light small">SESIÓN: <strong><?= htmlspecialchars($nombreUsuario) ?></strong> (<?= htmlspecialchars($user_role) ?>)</span>
                </div>

                <a href="devoluciones.php" class="btn btn-primary btn-sm fw-semibold px-3 py-2 text-nowrap">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Nueva Devolución
                </a>
            </div>
        </div>

        <?php if (!empty($mensaje)): ?>
            <div class="alert alert-<?= $tipo_alerta ?> alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
                <i class="bi bi-info-circle-fill me-2"></i><?= htmlspecialchars($mensaje) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="custom-card">
            <div class="table-responsive">
                <table class="table table-dark-custom align-middle mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Fecha y Hora</th>
                            <th>Insumo</th>
                            <th>Cant.</th>
                            <th>Colaborador</th>
                            <th>Departamento</th>
                            <th>Motivo</th>
                            <?php if (in_array($user_role, ['Administrador', 'Almacenista'])): ?>
                                <th>Registrado por</th>
                            <?php endif; ?>
                            <th>Observaciones</th>
                            <?php if ($user_role === 'Administrador'): ?>
                                <th class="text-center">Acciones</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($registros)): ?>
                            <?php foreach ($registros as $row): ?>
                                <tr>
                                    <td class="fw-bold text-secondary">#<?= $row['id'] ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-1 text-info small">
                                            <i class="bi bi-calendar3"></i>
                                            <span><?= date('d/m/Y', strtotime($row['fecha_registro'])) ?></span>
                                            <i class="bi bi-clock ms-1 text-secondary"></i>
                                            <span class="text-secondary"><?= date('H:i:s', strtotime($row['fecha_registro'])) ?></span>
                                        </div>
                                    </td>
                                    <td><strong class="text-white"><?= htmlspecialchars($row['insumo_nombre'] ?? 'Insumo no encontrado') ?></strong></td>
                                    <td><span class="badge bg-success"><?= intval($row['cantidad']) ?></span></td>
                                    <td class="fw-semibold text-white"><?= htmlspecialchars($row['colaborador']) ?></td>
                                    <td><span class="badge bg-secondary"><?= htmlspecialchars($row['departamento']) ?></span></td>
                                    <td>
                                        <span class="badge bg-warning text-dark">
                                            <?= htmlspecialchars($row['motivo']) ?>
                                        </span>
                                    </td>
                                    <?php if (in_array($user_role, ['Administrador', 'Almacenista'])): ?>
                                        <td><span class="text-info small"><i class="bi bi-person"></i> <?= htmlspecialchars($row['usuario_registra'] ?? 'Sistema') ?></span></td>
                                    <?php endif; ?>
                                    <td><span class="text-muted small"><?= htmlspecialchars($row['comentarios'] ?: 'Sin observaciones') ?></span></td>
                                    
                                    <?php if ($user_role === 'Administrador'): ?>
                                        <td class="text-center">
                                            <form action="ver_devoluciones.php" method="POST" onsubmit="return confirm('¿Estás seguro de que deseas eliminar este registro de devolución?');" style="display:inline;">
                                                <input type="hidden" name="eliminar_id" value="<?= $row['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm px-2 py-1" title="Eliminar registro">
                                                    <i class="bi bi-trash-fill"></i>
                                                </button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php 
                                $colspan = 8;
                                if (in_array($user_role, ['Administrador', 'Almacenista'])) $colspan++;
                                if ($user_role === 'Administrador') $colspan++;
                            ?>
                            <tr>
                                <td colspan="<?= $colspan ?>" class="text-center py-5 text-secondary">
                                    <i class="bi bi-folder2-open fs-2 d-block mb-2"></i>
                                    No hay registros de devoluciones disponibles en la base de datos.
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