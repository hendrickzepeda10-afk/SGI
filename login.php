<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Si ya hay sesión pero quieres evitar bucles por errores en index.php, 
// puedes comentar temporalmente esta validación si te vuelve a fallar:
if (isset($_SESSION['user_id']) && !isset($_GET['force'])) {
    header("Location: index.php");
    exit();
}

require_once 'config/database.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameOrEmail = trim($_POST['username'] ?? '');
    $password        = $_POST['password'] ?? '';

    if (!empty($usernameOrEmail) && !empty($password)) {
        try {
            $database = new Database();
            $pdo = $database->getConnection();
            
            $stmt = $pdo->prepare("
                SELECT id, username, full_name, email, password_hash, role, role_id
                FROM users
                WHERE (username = ? OR email = ?)
                LIMIT 1
            ");
            $stmt->execute([$usernameOrEmail, $usernameOrEmail]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role_id']   = $user['role_id'];
                $_SESSION['role']      = $user['role'];

                header("Location: index.php");
                exit();
            } else {
                $error = "Nombre de usuario/correo o contraseña incorrectos.";
            }
        } catch (PDOException $e) {
            $error = "Error al conectar con la base de datos: " . $e->getMessage();
        }
    } else {
        $error = "Por favor, completa todos los campos.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SGI Enterprise | Iniciar Sesión</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    <link rel="stylesheet" href="css/estilo.css">

</head>
<body>

    <div class="login-container">
        <div class="text-center mb-4 animate__animated animate__fadeInDown">
            <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-10 border border-primary border-opacity-25 rounded-circle p-3 mb-3">
                <i class="bi bi-box-seam-fill text-primary fs-2"></i>
            </div>
            <h3 class="fw-bold text-white mb-1">SGI Enterprise</h3>
            <p class="text-secondary small mb-0">Sistema de Gestión de Inventario RAMA TEST</p>
        </div>

        <div class="card-custom p-4 animate__animated animate__fadeInUp">
            <h5 class="fw-bold mb-3"><i class="bi bi-box-arrow-in-right me-2 text-primary"></i>Iniciar Sesión</h5>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm py-2 small animate__animated animate__shakeX" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i><?= $error ?>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST">
                <div class="mb-3">
                    <label class="form-label text-secondary fw-medium">Usuario o Correo</label>
                    <input type="text" name="username" class="form-control" required placeholder="Ingrese su usuario o correo">
                </div>

                <div class="mb-4">
                    <label class="form-label text-secondary fw-medium">Contraseña</label>
                    <input type="password" name="password" class="form-control" required placeholder="••••••••">
                </div>

                <button type="submit" class="btn btn-primary w-100 fw-semibold py-2">
                    <i class="bi bi-unlock me-1"></i> Entrar al Sistema
                </button>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>