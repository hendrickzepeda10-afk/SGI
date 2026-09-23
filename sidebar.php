<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!-- Sidebar Navegación -->
<aside class="sidebar">
    <div class="sidebar-brand d-flex align-items-center gap-2">
        <i class="bi bi-layers-fill text-primary fs-4"></i>
        <span>SGI Enterprise</span>
    </div>
    <div class="py-3">
        <small class="text-uppercase text-muted px-3 fw-bold" style="font-size: 0.7rem; letter-spacing: 0.8px;">Menú Principal</small>
        <nav class="mt-2 nav flex-column">
            <a href="index.php" class="nav-link-custom">
                <i class="bi bi-grid-fill"></i> Dashboard
            </a>

            <!-- Solo Administrador y Almacenista -->
            <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['Administrador', 'Almacenista'])): ?>
                <a href="insumos.php" class="nav-link-custom">
                    <i class="bi bi-box-seam-fill"></i> Catálogo de Insumos
                </a>
            <?php endif; ?>

            <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['Administrador', 'Almacenista'])): ?>
                <a href="entradas.php" class="nav-link-custom">
                    <i class="bi bi-arrow-down-left-square-fill text-success"></i> Recepción (Entradas)
                </a>
                <a href="salidas.php" class="nav-link-custom">
                    <i class="bi bi-arrow-up-right-square-fill text-danger"></i> Distribución (Salidas)
                </a>
            <?php endif; ?>

            <!-- Visible para todos -->
            <a href="Pedir.php" class="nav-link-custom">
                <i class="bi bi-cart-plus-fill text-warning"></i> Solicitud de Insumo
            </a>

            <!-- Historial de Solicitudes (Solo Admin y Almacenista) -->
            <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['Administrador', 'Almacenista'])): ?>
                <a href="ver_solicitudes.php" class="nav-link-custom">
                    <i class="bi bi-clock-history text-primary"></i> Historial de Solicitudes
                </a>
            <?php endif; ?>

            <!-- Devolución de Insumo (Visible para todos: Admin, Almacenista y Operador) -->
            <?php if (isset($_SESSION['role'])): ?>
                <a href="devoluciones.php" class="nav-link-custom">
                    <i class="bi bi-arrow-counterclockwise text-info"></i> Devolución de Insumo
                </a>
            <?php endif; ?>

            <!-- Historial de Devoluciones (SOLO Administrador y Almacenista) -->
            <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['Administrador', 'Almacenista'])): ?>
                <a href="ver_devoluciones.php" class="nav-link-custom">
                    <i class="bi bi-journal-text text-info"></i> Historial de Devoluciones
                </a>
            <?php endif; ?>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'Administrador'): ?>
                <a href="ajustes.php" class="nav-link-custom">
                    <i class="bi bi-sliders2 text-info"></i> Ajustes de Stock
                </a>
            <?php endif; ?>

            <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['Administrador', 'Almacenista'])): ?>
                <a href="alertas.php" class="nav-link-custom">
                    <i class="bi bi-exclamation-triangle-fill text-warning"></i> Alertas de Stock
                </a>
            <?php endif; ?>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'Administrador'): ?>
                <a href="usuarios.php" class="nav-link-custom">
                    <i class="bi bih bi-people-fill text-primary"></i> Roles / Accesos
                </a>
            <?php endif; ?>
        </nav>
    </div>
</aside>