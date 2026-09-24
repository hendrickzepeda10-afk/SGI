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

$mensaje = "";
$error = "";

// Crear tabla de usuarios si no existe
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id BIGINT AUTO_INCREMENT PRIMARY KEY
    ) ENGINE=InnoDB;");

    function addColumnIfNotExists($pdo, $table, $column, $definition) {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }

    addColumnIfNotExists($pdo, 'users', 'username', 'VARCHAR(50) NOT NULL UNIQUE');
    addColumnIfNotExists($pdo, 'users', 'full_name', 'VARCHAR(100) NOT NULL');
    addColumnIfNotExists($pdo, 'users', 'email', 'VARCHAR(100) NULL');
    addColumnIfNotExists($pdo, 'users', 'password_hash', 'VARCHAR(255) NOT NULL');
    addColumnIfNotExists($pdo, 'users', 'role', "VARCHAR(50) DEFAULT 'Operador'");
    addColumnIfNotExists($pdo, 'users', 'role_id', 'INT NULL');
    addColumnIfNotExists($pdo, 'users', 'is_active', 'TINYINT(1) DEFAULT 1');
    addColumnIfNotExists($pdo, 'users', 'created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');

} catch (PDOException $e) {
    // Manejo silencioso o log
}

// Procesar acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action =$_POST['action'] ?? '';

    // ELIMINAR USUARIO
    if ($action === 'delete') {
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt =$pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$id]);$_SESSION['mensaje'] = "Usuario eliminado correctamente.";
            } catch (Exception $e) {$_SESSION['error'] = "Error al eliminar usuario: " . $e->getMessage();
            }
        }
        header("Location: usuarios.php");
        exit();
    }

    // CAMBIAR ESTADO (ACTIVAR / DESACTIVAR)
    if ($action === 'toggle_status') {
        $id = (int)($_POST['user_id'] ?? 0);
        $current_status = (int)($_POST['current_status'] ?? 0);
        $new_status =$current_status === 1 ? 0 : 1;

        if ($id > 0) {
            try {
                $stmt =$pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                $stmt->execute([$new_status, $id]);$_SESSION['mensaje'] = "Estado actualizado correctamente.";
            } catch (Exception $e) {$_SESSION['error'] = "Error al cambiar estado: " . $e->getMessage();
            }
        }
        header("Location: usuarios.php");
        exit();
    }

    // CREAR USUARIO
    if ($action === 'create') {
        $username  = trim($_POST['username'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  =$_POST['password'] ?? '';
        $role      =$_POST['role'] ?? 'Operador';

        if (!empty($username) && !empty($full_name) && !empty($password)) {
            try {
                $passHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt =$pdo->prepare("
                    INSERT INTO users (username, full_name, email, password_hash, role, is_active) 
                    VALUES (?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([$username, $full_name,$email, $passHash,$role]);
                $_SESSION['mensaje'] = "Usuario '{$username}' creado exitosamente.";
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {$_SESSION['error'] = "El nombre de usuario '{$username}' o correo ya existe.";
                } else {
                    $_SESSION['error'] = "Error al crear usuario: " . $e->getMessage();
                }
            }
        } else {
            $_SESSION['error'] = "Por favor completa los campos obligatorios.";
        }
        header("Location: usuarios.php");
        exit();
    }

    // EDITAR USUARIO
    if ($action === 'update') {
        $id        = (int)($_POST['user_id'] ?? 0);
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $role      =$_POST['role'] ?? 'Operador';
        $password  =$_POST['password'] ?? '';

        if ($id > 0 && !empty($full_name)) {
            try {
                if (!empty($password)) {
                    $passHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt =$pdo->prepare("UPDATE users SET full_name = ?, email = ?, role = ?, password_hash = ? WHERE id = ?");
                    $stmt->execute([$full_name, $email,$role, $passHash,$id]);
                } else {
                    $stmt =$pdo->prepare("UPDATE users SET full_name = ?, email = ?, role = ? WHERE id = ?");
                    $stmt->execute([$full_name,$email, $role,$id]);
                }
                $_SESSION['mensaje'] = "Usuario actualizado correctamente.";
            } catch (PDOException $e) {$_SESSION['error'] = "Error al actualizar usuario: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Por favor completa los campos requeridos.";
        }
        header("Location: usuarios.php");
        exit();
    }
}

// Recuperar mensajes de sesión
$mensaje =$_SESSION['mensaje'] ?? "";
$error   =$_SESSION['error'] ?? "";
unset($_SESSION['mensaje'],$_SESSION['error']);

// Consultar lista de usuarios
$usuarios = [];
try {
    $stmt =$pdo->query("SELECT id, username, full_name, email, role, is_active, created_at FROM users ORDER BY id DESC");
    $usuarios =$stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {$error = "Error al cargar usuarios: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SGI | Gestión de Usuarios</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- Hoja de estilos centralizada -->
    <link rel="stylesheet" href="css/estilo.css">
</head>
<body>

    <?php include './sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <div class="mb-4">
            <h2 class="fw-bold text-white mb-1"><i class="bi bi-people text-primary me-2"></i>Registro de Usuarios</h2>
            <p class="text-secondary small mb-0">Crea una nueva cuenta de acceso y asigna roles al sistema</p>
        </div>

        <!-- Alertas -->
        <?php if (!empty($mensaje)): ?>
            <div class="alert alert-success alert-dismissible fade show bg-dark text-success border-success mb-4 shadow-sm" role="alert">
                <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($mensaje) ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show bg-dark text-danger border-danger mb-4 shadow-sm" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Formulario Nuevo Usuario -->
            <div class="col-lg-4">
                <div class="custom-card">
                    <h5 class="text-white fw-bold mb-3 pb-2 border-bottom border-secondary d-flex align-items-center gap-2">
                        <i class="bi bi-person-plus text-primary"></i> Nuevo Usuario
                    </h5>
                    <form action="usuarios.php" method="POST">
                        <input type="hidden" name="action" value="create">

                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-semibold">Nombre de Usuario</label>
                            <input type="text" name="username" class="form-control bg-dark text-white border-secondary" required placeholder="usuario_ej">
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-semibold">Nombre Completo</label>
                            <input type="text" name="full_name" class="form-control bg-dark text-white border-secondary" required placeholder="Juan López">
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-semibold">Correo Electrónico</label>
                            <input type="email" name="email" class="form-control bg-dark text-white border-secondary" placeholder="juan@empresa.com">
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-semibold">Contraseña</label>
                            <input type="password" name="password" class="form-control bg-dark text-white border-secondary" required placeholder="••••••••">
                        </div>

                        <div class="mb-4">
                            <label class="form-label text-secondary small fw-semibold">Rol / Perfil *</label>
                            <select name="role" class="form-select bg-dark text-white border-secondary" required>
                                <option value="Colaborador">Colaborador</option>
                                <option value="Almacenista">Almacenista</option>
                                <option value="Administrador">Administrador</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 d-flex align-items-center justify-content-center gap-2">
                            <i class="bi bi-person-plus"></i> Registrar Usuario
                        </button>
                    </form>
                </div>
            </div>

            <!-- Tabla de Usuarios -->
            <div class="col-lg-8">
                <div class="custom-card">
                    <h5 class="text-white fw-bold mb-3 pb-2 border-bottom border-secondary">
                        Usuarios del Sistema
                    </h5>
                    <div class="table-responsive">
                        <table class="table table-dark-custom align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Usuario / Nombre</th>
                                    <th>Correo</th>
                                    <th>Rol</th>
                                    <th>Estado</th>
                                    <th class="text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($usuarios) > 0): ?>
                                    <?php foreach ($usuarios as$usr): ?>
                                        <tr>
                                            <td class="fw-bold text-secondary">#<?= $usr['id'] ?></td>
                                            <td>
                                                <div class="fw-bold text-white"><?= htmlspecialchars($usr['username'] ?? '') ?></div>
                                                <div class="text-secondary small"><?= htmlspecialchars($usr['full_name'] ?? '') ?></div>
                                            </td>
                                            <td class="text-secondary small"><?= htmlspecialchars($usr['email'] ?? 'N/D') ?></td>
                                            <td>
                                                <span class="badge badge-motivo px-2 py-1"><?= htmlspecialchars($usr['role'] ?? 'Operador') ?></span>
                                            </td>
                                            <td>
                                                <?php if ((int)$usr['is_active'] === 1): ?>
                                                    <span class="badge bg-success">Activo</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inactivo</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <div class="d-flex gap-1 justify-content-end align-items-center">
                                                    <!-- Editar -->
                                                    <button type="button" 
                                                            class="btn btn-sm btn-outline-info" 
                                                            title="Editar"
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editModal"
                                                            data-id="<?= $usr['id'] ?>"
                                                            data-username="<?= htmlspecialchars($usr['username']) ?>"
                                                            data-fullname="<?= htmlspecialchars($usr['full_name']) ?>"
                                                            data-email="<?= htmlspecialchars($usr['email']) ?>"
                                                            data-role="<?= htmlspecialchars($usr['role']) ?>">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>

                                                    <!-- Cambiar Estado -->
                                                    <form action="usuarios.php" method="POST" style="margin:0;">
                                                        <input type="hidden" name="action" value="toggle_status">
                                                        <input type="hidden" name="user_id" value="<?= $usr['id'] ?>">
                                                        <input type="hidden" name="current_status" value="<?= $usr['is_active'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-warning" title="Cambiar Estado">
                                                            <i class="bi bi-power"></i>
                                                        </button>
                                                    </form>

                                                    <!-- Eliminar -->
                                                    <form action="usuarios.php" method="POST" style="margin:0;" onsubmit="return confirm('¿Eliminar usuario?');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="user_id" value="<?= $usr['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-5 text-secondary">
                                            <i class="bi bi-folder2-open fs-2 d-block mb-2"></i>
                                            No hay usuarios registrados.
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

    <!-- Modal Editar Usuario -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark border-secondary text-white">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title fs-6 fw-bold">
                        <i class="bi bi-pencil-square me-2 text-primary"></i>Editar Usuario
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="usuarios.php" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="user_id" id="edit_user_id">

                        <div class="mb-3">
                            <label class="form-label text-secondary small">Nombre de Usuario</label>
                            <input type="text" id="edit_username" class="form-control bg-dark text-white border-secondary opacity-50" disabled readonly>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-secondary small">Nombre Completo *</label>
                            <input type="text" name="full_name" id="edit_full_name" class="form-control bg-dark text-white border-secondary" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-secondary small">Correo Electrónico</label>
                            <input type="email" name="email" id="edit_email" class="form-control bg-dark text-white border-secondary">
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-secondary small">Rol / Perfil *</label>
                            <select name="role" id="edit_role" class="form-select bg-dark text-white border-secondary" required>
                                <option value="Colaborador">Colaborador</option>
                                <option value="Almacenista">Almacenista</option>
                                <option value="Administrador">Administrador</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-secondary small">Nueva Contraseña <span class="text-muted">(Dejar en blanco para mantener)</span></label>
                            <input type="password" name="password" class="form-control bg-dark text-white border-secondary" placeholder="••••••••">
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary btn-sm px-3">Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const editModal = document.getElementById('editModal');
        if (editModal) {
            editModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                
                document.getElementById('edit_user_id').value = button.getAttribute('data-id');
                document.getElementById('edit_username').value = button.getAttribute('data-username');
                document.getElementById('edit_full_name').value = button.getAttribute('data-fullname');
                document.getElementById('edit_email').value = button.getAttribute('data-email');
                document.getElementById('edit_role').value = button.getAttribute('data-role');
            });
        }
    </script>
</body>
</html>