<?php
// Iniciar sesión y validar autenticación
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Validación básica de seguridad: Opcional, descomenta si requieres login obligatorio
/*
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
*/

// Activar reporte de errores para depuración
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config/database.php';

$database = new Database();
$pdo =$database->getConnection();

$mensaje = "";
$error = "";

$DEFAULT_LOCATION_ID = 1;

// Insertar categorías por defecto si la tabla 'categories' está vacía
try {
    $checkCat =$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    if ($checkCat == 0) {$pdo->exec("INSERT INTO categories (name, description) VALUES 
            ('Oficina y Papelería', 'Insumos generales de oficina'),
            ('Aseo y Limpieza', 'Productos de limpieza e higiene'),
            ('Tecnología', 'Consumibles informáticos y accesorios'),
            ('Mantenimiento', 'Herramientas y repuestos')
        ");
    }
} catch (PDOException $e) {
    // Si la tabla no existe o falla la consulta
}

// Crear nueva categoría vía Formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) &&$_POST['action'] === 'new_category') {
    $cat_name = trim($_POST['cat_name'] ?? '');
    if (!empty($cat_name)) {
        try {
            $stmtNewCat =$pdo->prepare("INSERT INTO categories (name) VALUES (?)");
            $stmtNewCat->execute([$cat_name]);
            $mensaje = "Categoría '$cat_name' creada correctamente.";
        } catch (PDOException $e) {$error = "Error al crear la categoría: " . $e->getMessage();
        }
    }
}

// Registrar o Actualizar Insumo/Producto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] !== 'new_category')) {$sku             = trim($_POST['sku'] ?? '');$barcode         = trim($_POST['barcode'] ?? '');$name            = trim($_POST['name'] ?? '');$category_id     = (int)($_POST['category_id'] ?? 0);$unit_of_measure = trim($_POST['unit_of_measure'] ?? 'Unidad');$min_stock       = (int)($_POST['min_stock'] ?? 5);$initial_stock   = (float)($_POST['initial_stock'] ?? 0);$id              = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    // Verificar datos
    if (!empty($sku) && !empty($name) &&$category_id > 0) {
        try {
            $pdo->beginTransaction();

            if ($id > 0) {
                // Editar Producto
                $stmtCheckSku =$pdo->prepare("SELECT id FROM products WHERE sku = ? AND id != ?");
                $stmtCheckSku->execute([$sku,$id]);
                if ($stmtCheckSku->fetch()) {
                    throw new Exception("El SKU '$sku' ya está registrado en otro insumo.");
                }

                $stmt =$pdo->prepare("UPDATE products SET sku=?, barcode=?, name=?, category_id=?, unit_of_measure=?, min_stock=? WHERE id=?");
                $stmt->execute([$sku, empty($barcode) ? NULL : $barcode, $name,$category_id, $unit_of_measure,$min_stock, $id]);$mensaje = "Insumo actualizado correctamente.";
            } else {
                // Insertar Producto
                $stmtCheckSku =$pdo->prepare("SELECT id FROM products WHERE sku = ?");
                $stmtCheckSku->execute([$sku]);
                if ($stmtCheckSku->fetch()) {
                    throw new Exception("El SKU '$sku' ya se encuentra registrado.");
                }

                $stmt =$pdo->prepare("INSERT INTO products (sku, barcode, name, category_id, unit_of_measure, min_stock) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$sku, 
                    empty($barcode) ? NULL : $barcode, 
                    $name,$category_id, 
                    $unit_of_measure,$min_stock
                ]);
                
                $product_id =$pdo->lastInsertId();

                // Asegurar que la ubicación exista antes de insertar stock
                $stmtLoc =$pdo->prepare("INSERT IGNORE INTO locations (id, name, type) VALUES (?, 'Almacén Principal', 'WAREHOUSE')");
                $stmtLoc->execute([$DEFAULT_LOCATION_ID]);

                // Registrar Stock Inicial
                $stmtStock =$pdo->prepare("INSERT INTO inventory_stock (product_id, location_id, current_stock) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE current_stock = current_stock + VALUES(current_stock)");
                $stmtStock->execute([$product_id, $DEFAULT_LOCATION_ID,$initial_stock]);

                $mensaje = "Insumo registrado con éxito.";
            }

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {$pdo->rollBack();
            }
            $error = "<strong>Error:</strong> " . $e->getMessage();
        }
    } else {
        $error = "<strong>Faltan campos obligatorios:</strong> Asegúrate de ingresar el SKU, Nombre y Seleccionar una Categoría válida.";
    }
}

// Activar / Desactivar Producto
if (isset($_GET['toggle_status'])) {
    $id_toggle = (int)$_GET['toggle_status'];
    try {
        $stmtToggle =$pdo->prepare("UPDATE products SET is_active = IF(is_active=1, 0, 1) WHERE id = ?");
        $stmtToggle->execute([$id_toggle]);
        header("Location: insumos.php");
        exit;
    } catch (PDOException $e) {$error = "Error al cambiar el estado: " . $e->getMessage();
    }
}

// Eliminar Producto (Actualizado para limpiar dependencias de llaves foráneas)
if (isset($_GET['delete_id'])) {
    $id_delete = (int)$_GET['delete_id'];
    try {
        $pdo->beginTransaction();

        // 1. Eliminar movimientos de inventario asociados
        $stmtDelMov =$pdo->prepare("DELETE FROM inventory_movements WHERE product_id = ?");
        $stmtDelMov->execute([$id_delete]);

        // 2. Eliminar solicitudes asociadas
        $stmtDelSol =$pdo->prepare("DELETE FROM solicitudes WHERE product_id = ?");
        $stmtDelSol->execute([$id_delete]);

        // 3. Eliminar stock actual
        $stmtDelStock =$pdo->prepare("DELETE FROM inventory_stock WHERE product_id = ?");
        $stmtDelStock->execute([$id_delete]);

        // 4. Finalmente eliminar el producto
        $stmtDelProd =$pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmtDelProd->execute([$id_delete]);

        $pdo->commit();
        header("Location: insumos.php");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {$pdo->rollBack();
        }
        $error = "Error al eliminar el insumo: " . $e->getMessage();
    }
}

// Consultar Categorías
$categories = [];
try {
    $stmtCat =$pdo->query("SELECT * FROM categories ORDER BY name ASC");
    if ($stmtCat) {
        $categories =$stmtCat->fetchAll();
    }
} catch (PDOException $e) {$error = "Error al cargar categorías: " . $e->getMessage();
}

// Consultar Insumos / Productos
$products = [];
try {
    $queryProducts = "SELECT p.*, c.name as category_name, COALESCE(SUM(s.current_stock), 0) as stock_total 
                      FROM products p 
                      LEFT JOIN categories c ON p.category_id = c.id 
                      LEFT JOIN inventory_stock s ON p.id = s.product_id 
                      GROUP BY p.id 
                      ORDER BY p.id DESC";
    $stmtProd = $pdo->query($queryProducts);
    if ($stmtProd) {
        $products =$stmtProd->fetchAll();
    }
} catch (PDOException $e) {$error = "Error al cargar productos: " . $e->getMessage();
}

$userRole = strtolower($_SESSION['role'] ?? '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SGI | Catálogo de Insumos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        :root {
            --sidebar-width: 260px;
            --primary-color: #d8dbe3;
            --accent-color: #2563eb;
            --bg-body: #121318;
            --card-bg: #18191c;
            --card-border: #2d2f38;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-body);
            color: #f4f4f5;
            position: relative;
            min-height: 100vh;
            overflow-x: hidden;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes slideInSidebar {
            from { transform: translateX(-100%); }
            to { transform: translateX(0); }
        }

        .sidebar {
            width: var(--sidebar-width);
            background-color: #0f172a;
            min-height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 100;
            border-right: 1px solid #1e293b;
            animation: slideInSidebar 0.4s ease-out forwards;
        }

        .sidebar-brand {
            padding: 1.5rem 1.25rem;
            font-size: 1.15rem;
            font-weight: 700;
            color: #ffffff;
            border-bottom: 1px solid #1e293b;
        }

        .nav-link-custom {
            color: #94a3b8;
            padding: 0.65rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 500;
            font-size: 0.875rem;
            text-decoration: none;
            transition: transform 0.2s ease, background-color 0.2s ease, color 0.2s ease;
        }

        .nav-link-custom:hover, .nav-link-custom.active {
            color: #ffffff;
            background-color: rgba(255, 255, 255, 0.05);
            border-left: 3px solid var(--accent-color);
            transform: translateX(4px);
        }

        .nav-link-custom i {
            transition: transform 0.2s ease;
        }

        .nav-link-custom:hover i {
            transform: scale(1.15);
        }

        .main-content {
            margin-left: var(--sidebar-width);
            padding: 2rem 2.5rem;
            min-height: 100vh;
            animation: fadeIn 0.5s ease-out forwards;
        }

        .custom-card {
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 1.5rem;
        }

        .form-control, .form-select {
            background-color: #121316;
            border: 1px solid #333644;
            color: #fff;
        }

        .form-control:focus, .form-select:focus {
            background-color: #121316;
            border-color: var(--accent-color);
            color: #fff;
            box-shadow: none;
        }

        .table-dark-custom {
            background-color: transparent;
            color: #e2e8f0;
        }

        .table-dark-custom th {
            background-color: #141518;
            color: #94a3b8;
            border-bottom: 1px solid var(--card-border);
            font-size: 0.8rem;
            text-transform: uppercase;
        }

        .table-dark-custom td {
            border-bottom: 1px solid #282a32;
            vertical-align: middle;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <?php include './sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold text-white mb-1">Catálogo de Insumos</h3>
                <p class="text-muted mb-0">Gestión, registro y control del catálogo general de productos e insumos</p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-light px-3 py-2 fw-medium shadow-sm" data-bs-toggle="modal" data-bs-target="#modalCategoria">
                    <i class="bi bi-folder-plus me-1"></i> Nueva Categoría
                </button>
                <button class="btn btn-primary px-3 py-2 fw-medium shadow-sm" onclick="resetModalInsumo()" data-bs-toggle="modal" data-bs-target="#modalInsumo">
                    <i class="bi bi-plus-lg me-1"></i> Registrar Insumo
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

        <!-- Tabla -->
        <div class="custom-card shadow-sm">
            <div class="table-responsive">
                <table class="table table-dark-custom table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>SKU / CÓDIGO</th>
                            <th>INSUMO</th>
                            <th>CATEGORÍA</th>
                            <th>U. MEDIDA</th>
                            <th>STOCK ACTUAL</th>
                            <th>STOCK MÍN.</th>
                            <th>ESTADO</th>
                            <th class="text-end">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($products) > 0): ?>
                            <?php foreach ($products as $index =>$prod): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-white"><code><?= htmlspecialchars($prod['sku']) ?></code></div>
                                        <?php if (!empty($prod['barcode'])): ?>
                                            <small class="text-muted"><i class="bi bi-barcode"></i> <?= htmlspecialchars($prod['barcode']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-semibold text-white"><?= htmlspecialchars($prod['name']) ?></td>
                                    <td><span class="badge bg-secondary text-light"><?= htmlspecialchars($prod['category_name'] ?? 'Sin Categoría') ?></span></td>
                                    <td><?= htmlspecialchars($prod['unit_of_measure']) ?></td>
                                    <td>
                                        <span class="badge <?= $prod['stock_total'] <=$prod['min_stock'] ? 'bg-danger' : 'bg-success' ?>">
                                            <?= number_format($prod['stock_total'], 2) ?>
                                        </span>
                                    </td>
                                    <td><?= $prod['min_stock'] ?></td>
                                    <td>
                                        <span class="badge <?= $prod['is_active'] == 1 ? 'bg-success text-white' : 'bg-secondary' ?>">
                                            <?= $prod['is_active'] == 1 ? 'ACTIVO' : 'INACTIVO' ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-primary me-1" title="Editar Insumo" onclick='editarInsumo(<?= json_encode($prod) ?>)'>
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <a href="insumos.php?toggle_status=<?= $prod['id'] ?>" class="btn btn-sm btn-outline-warning me-1" title="Activar/Desactivar">
                                            <i class="bi bi-power"></i>
                                        </a>
                                        <a href="javascript:void(0);" onclick="confirmarEliminar(<?= $prod['id'] ?>, '<?= htmlspecialchars($prod['name'], ENT_QUOTES) ?>')" class="btn btn-sm btn-outline-danger" title="Eliminar Insumo">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                    No hay insumos registrados. Haz clic en <strong>"Registrar Insumo"</strong>.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modal Nuevo / Editar Insumo -->
    <div class="modal fade" id="modalInsumo" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content custom-card border-0 shadow-lg">
                <form action="insumos.php" method="POST">
                    <input type="hidden" name="id" id="product_id" value="">

                    <div class="modal-header border-bottom-0">
                        <h5 class="modal-title fw-bold text-white" id="modalInsumoTitle">Nuevo Insumo / Producto</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label text-secondary fw-medium">SKU *</label>
                                <input type="text" name="sku" id="sku" class="form-control" required placeholder="Ej: INS-001">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-secondary fw-medium">Código de Barras</label>
                                <input type="text" name="barcode" id="barcode" class="form-control" placeholder="Opcional">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-secondary fw-medium">Nombre del Insumo *</label>
                            <input type="text" name="name" id="name" class="form-control" required placeholder="Ej: Hojas Papel Bond A4">
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label text-secondary fw-medium">Categoría *</label>
                                <select name="category_id" id="category_id" class="form-select" required>
                                    <option value="">-- Seleccionar --</option>
                                    <?php foreach ($categories as$cat): ?>
                                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-secondary fw-medium">Unidad de Medida</label>
                                <input type="text" name="unit_of_measure" id="unit_of_measure" class="form-control" value="Unidad" placeholder="Ej: Caja, Paquete">
                            </div>
                        </div>
                        <div class="row g-2" id="stockContainer">
                            <div class="col-md-6">
                                <label class="form-label text-secondary fw-medium">Stock Inicial</label>
                                <input type="number" step="0.01" name="initial_stock" id="initial_stock" class="form-control" value="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-secondary fw-medium">Stock Mínimo</label>
                                <input type="number" name="min_stock" id="min_stock" class="form-control" value="5">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-top-0">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary px-4" id="btnGuardarInsumo">Guardar Insumo</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Nueva Categoría -->
    <div class="modal fade" id="modalCategoria" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content custom-card border-0 shadow-lg">
                <form action="insumos.php" method="POST">
                    <input type="hidden" name="action" value="new_category">
                    <div class="modal-header border-bottom-0">
                        <h5 class="modal-title fw-bold text-white">Nueva Categoría</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label text-secondary fw-medium">Nombre Categoría *</label>
                            <input type="text" name="cat_name" class="form-control" required placeholder="Ej: Material Impreso">
                        </div>
                    </div>
                    <div class="modal-footer border-top-0">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary px-3">Crear</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function resetModalInsumo() {
            document.getElementById('product_id').value = '';
            document.getElementById('modalInsumoTitle').innerText = 'Nuevo Insumo / Producto';
            document.getElementById('btnGuardarInsumo').innerText = 'Guardar Insumo';
            document.getElementById('sku').value = '';
            document.getElementById('barcode').value = '';
            document.getElementById('name').value = '';
            document.getElementById('category_id').value = '';
            document.getElementById('unit_of_measure').value = 'Unidad';
            document.getElementById('initial_stock').value = '0';
            document.getElementById('min_stock').value = '5';
            document.getElementById('stockContainer').style.display = 'flex';
        }

        function editarInsumo(prod) {
            document.getElementById('product_id').value = prod.id;
            document.getElementById('modalInsumoTitle').innerText = 'Editar Insumo / Producto';
            document.getElementById('btnGuardarInsumo').innerText = 'Actualizar Insumo';
            document.getElementById('sku').value = prod.sku;
            document.getElementById('barcode').value = prod.barcode || '';
            document.getElementById('name').value = prod.name;
            document.getElementById('category_id').value = prod.category_id;
            document.getElementById('unit_of_measure').value = prod.unit_of_measure;
            document.getElementById('min_stock').value = prod.min_stock;
            
            document.getElementById('initial_stock').value = '0';
            document.getElementById('stockContainer').style.display = 'none';

            var myModal = new bootstrap.Modal(document.getElementById('modalInsumo'));
            myModal.show();
        }

        function confirmarEliminar(id, nombre) {
            if (confirm(`¿Estás seguro de que deseas eliminar el insumo "${nombre}"?\nEsta acción no se puede deshacer.`)) {
                window.location.href = `insumos.php?delete_id=${id}`;
            }
        }
    </script>
</body>
</html>