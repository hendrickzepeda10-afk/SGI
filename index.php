<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SGI | Sistema de Gestión de Inventario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- Enlace al archivo CSS centralizado -->
    <link rel="stylesheet" href="css/estilo.css">
</head>
<body>

    <?php include './sidebar.php'; ?>

    <!-- Contenido Principal centrado -->
    <main class="main-content centered-layout">
        <div class="custom-card text-start" style="max-width: 750px; width: 100%;">
            <div class="mb-3 text-primary fs-3">
                <i class="bi bi-box-seam"></i>
            </div>
            <h1 class="fw-bold text-white mb-3 fs-2">Bienvenido al sistema de gestión de inventario</h1>
            <p class="text-secondary mb-4 fs-6">
                Plataforma para el control de tus insumos. Desde aquí podrás <strong class="text-white">registrar</strong> nuevos productos, <strong class="text-white">editar</strong> existencias, <strong class="text-white">administrar entradas y salidas</strong>, y supervisar el stock en tiempo real.
            </p>
            <div class="d-flex flex-wrap gap-3">
                <a href="pedir.php" class="btn btn-primary px-4 py-2 fw-medium d-flex align-items-center gap-2">
                    <i class="bi bi-box"></i> Pedir insumo
                </a>
                <a href="devoluciones.php" class="btn btn-outline-light px-4 py-2 fw-medium d-flex align-items-center gap-2 border-secondary text-secondary">
                    <i class="bi bi-box-arrow-in-down"></i> Devolver insumo
                </a>
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