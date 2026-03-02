<?php
session_start();
// Habilitar errores temporalmente para debug en Hostinger
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/config.php';

// --- RUTAS API PARA LLAMADAS FETCH (AJAX) ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    $pdo = getDBConnection();

    // --- ENDPOINTS PARA CLIENTES (No requieren login de admin) ---

    // Aprobación de Documento Funcional
    if ($_GET['action'] === 'approve_document' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($data['propuesta_id'])) {
            try {
                $stmt = $pdo->prepare("INSERT INTO aprobaciones (propuesta_id, tipo, ip_address) VALUES (:pid, 'documento_funcional', :ip)");
                $stmt->execute([':pid' => (int)$data['propuesta_id'], ':ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
                echo json_encode(['success' => true]);
            }
            catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        }
        else {
            echo json_encode(['success' => false, 'message' => 'Falta propuesta_id']);
        }
        exit;
    }

    // Aprobación de Presupuesto
    if ($_GET['action'] === 'approve_presupuesto' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($data['propuesta_id'])) {
            try {
                $stmt = $pdo->prepare("INSERT INTO aprobaciones (propuesta_id, tipo, ip_address) VALUES (:pid, 'presupuesto', :ip)");
                $stmt->execute([':pid' => (int)$data['propuesta_id'], ':ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
                echo json_encode(['success' => true]);
            }
            catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        }
        else {
            echo json_encode(['success' => false, 'message' => 'Falta propuesta_id']);
        }
        exit;
    }

    // Rechazo / Feedback de Presupuesto
    if ($_GET['action'] === 'reject_presupuesto' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($data['propuesta_id']) && isset($data['comentario'])) {
            try {
                $stmt = $pdo->prepare("INSERT INTO feedback_presupuesto (propuesta_id, tipo_accion, comentario) VALUES (:pid, 'presupuesto_rechazado_o_cambios', :comment)");
                $stmt->execute([':pid' => (int)$data['propuesta_id'], ':comment' => trim($data['comentario'])]);
                echo json_encode(['success' => true]);
            }
            catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        }
        else {
            echo json_encode(['success' => false, 'message' => 'Falta propuesta_id o comentario']);
        }
        exit;
    }

    // --- ENDPOINTS PARA ADMIN (Requieren sesión activa) ---
    if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
        echo json_encode(['success' => false, 'message' => 'No autorizado']);
        exit;
    }

    // Toggle activo/inactivo
    if ($_GET['action'] === 'toggle_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($data['id']) && isset($data['status'])) {
            try {
                $stmt = $pdo->prepare("UPDATE propuestas SET status = :status WHERE id = :id");
                $stmt->execute([':status' => (int)$data['status'], ':id' => (int)$data['id']]);
                echo json_encode(['success' => true]);
            }
            catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        }
        exit;
    }

    // Toggle aprobación (manual admin)
    if ($_GET['action'] === 'toggle_approval' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($data['propuesta_id']) && isset($data['tipo'])) {
            try {
                if ($data['status'] == 1) {
                    $stmt = $pdo->prepare("INSERT OR IGNORE INTO aprobaciones (propuesta_id, tipo, ip_address) VALUES (:pid, :tipo, 'admin_manual')");
                    $stmt->execute([':pid' => (int)$data['propuesta_id'], ':tipo' => $data['tipo']]);
                }
                else {
                    $stmt = $pdo->prepare("DELETE FROM aprobaciones WHERE propuesta_id = :pid AND tipo = :tipo");
                    $stmt->execute([':pid' => (int)$data['propuesta_id'], ':tipo' => $data['tipo']]);
                }
                echo json_encode(['success' => true]);
            }
            catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        }
        exit;
    }

    // Eliminar propuesta
    if ($_GET['action'] === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($data['id'])) {
            try {
                $stmt = $pdo->prepare("DELETE FROM propuestas WHERE id = :id");
                $stmt->execute([':id' => (int)$data['id']]);
                echo json_encode(['success' => true]);
            }
            catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        }
        else {
            echo json_encode(['success' => false, 'message' => 'ID no proporcionado']);
        }
        exit;
    }

    // Eliminar Presupuesto PDF
    if ($_GET['action'] === 'delete_pdf' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($data['id'])) {
            try {
                // Primero obtenemos el nombre del archivo para borrarlo (opcional)
                $stmtGet = $pdo->prepare("SELECT presupuesto_pdf FROM propuestas WHERE id = ?");
                $stmtGet->execute([(int)$data['id']]);
                $filename = $stmtGet->fetchColumn();

                if ($filename && file_exists(__DIR__ . '/uploads/presupuestos/' . $filename)) {
                    @unlink(__DIR__ . '/uploads/presupuestos/' . $filename);
                }

                $stmt = $pdo->prepare("UPDATE propuestas SET presupuesto_pdf = NULL WHERE id = :id");
                $stmt->execute([':id' => (int)$data['id']]);
                echo json_encode(['success' => true]);
            }
            catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        }
        exit;
    }

    // Subir Presupuesto PDF
    if ($_GET['action'] === 'upload_presupuesto' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_FILES['pdf_file']) && isset($_POST['propuesta_id'])) {
            $file = $_FILES['pdf_file'];
            $propuesta_id = (int)$_POST['propuesta_id'];
            if ($file['error'] === UPLOAD_ERR_OK && strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'pdf') {
                $filename = 'presupuesto_' . $propuesta_id . '_' . time() . '.pdf';
                $dest = __DIR__ . '/uploads/presupuestos/' . $filename;
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    $stmt = $pdo->prepare("UPDATE propuestas SET presupuesto_pdf = :pdf WHERE id = :id");
                    $stmt->execute([':pdf' => $filename, ':id' => $propuesta_id]);
                    echo json_encode(['success' => true, 'filename' => $filename]);
                }
                else {
                    echo json_encode(['success' => false, 'message' => 'Error al mover archivo']);
                }
            }
            else {
                echo json_encode(['success' => false, 'message' => 'Archivo inválido o no es PDF']);
            }
        }
        exit;
    }

    // SAVE MEMBER API
    if (($_GET['action'] ?? '') === 'save_member' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log("Admin: hit save_member API");
        $id = $_POST['id'] ?? '';
        $nombre = trim($_POST['nombre'] ?? '');
        $cargo = trim($_POST['cargo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $orden = (int)($_POST['orden'] ?? 0);
        $foto_url = $_POST['current_foto'] ?? '';

        if (empty($nombre)) {
            echo json_encode(['success' => false, 'message' => 'Nombre es requerido']);
            exit;
        }

        $uploadError = null;
        if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['foto'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $filename = 'team_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                $uploadDir = __DIR__ . '/uploads/equipo/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $dest = $uploadDir . $filename;
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    $foto_url = 'uploads/equipo/' . $filename;
                }
                else {
                    $uploadError = "Error al guardar la imagen en el servidor.";
                }
            }
            else {
                $uploadError = "Formato de imagen no permitido.";
            }
        }

        if ($uploadError) {
            echo json_encode(['success' => false, 'message' => $uploadError]);
            exit;
        }

        try {
            if ($id) {
                $stmt = $pdo->prepare("UPDATE equipo SET nombre = ?, cargo = ?, descripcion = ?, foto_url = ?, orden = ? WHERE id = ?");
                $stmt->execute([$nombre, $cargo, $descripcion, $foto_url, $orden, $id]);
            }
            else {
                $stmt = $pdo->prepare("INSERT INTO equipo (nombre, cargo, descripcion, foto_url, orden) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$nombre, $cargo, $descripcion, $foto_url, $orden]);
            }
            echo json_encode(['success' => true]);
        }
        catch (Exception $e) {
            error_log("Admin Error saving member: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error de BD: ' . $e->getMessage()]);
        }
        exit;
    }

    // DELETE MEMBER API
    if ($_GET['action'] === 'delete_member' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $_POST['id'] ?? '';
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM equipo WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
        }

        else {
            echo json_encode(['success' => false, 'message' => 'ID no proporcionado']);
        }
        exit;
    }

    exit;
}

// --- LOGIN ---
$error_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if ($_POST['password'] === ADMIN_PASSWORD) {
        $_SESSION['is_admin'] = true;
        header("Location: admin.php");
        exit;
    }
    else {
        $error_msg = 'Contraseña incorrecta.';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin.php");
    exit;
}

$is_logged_in = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
$pdo = getDBConnection();
$base_path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');

// --- CRUD & DATA ---
if ($is_logged_in) {
    // Save Proposal
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_proposal'])) {
        try {
            $slug = preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower(trim($_POST['slug'])));
            $client_name = trim($_POST['client_name']);
            $pin = trim($_POST['pin']);
            $html_content = $_POST['html_content'];
            $id = isset($_POST['id']) && !empty($_POST['id']) ? (int)$_POST['id'] : null;
            $sent_date = trim($_POST['sent_date'] ?? '');
            $version = trim($_POST['version'] ?? 'v1.0');
            $equipo_ids = isset($_POST['equipo_ids']) && is_array($_POST['equipo_ids']) ? json_encode(array_map('intval', $_POST['equipo_ids'])) : '[]';

            if ($id) {
                if (isset($_POST['new_version']) && $_POST['new_version'] == '1') {
                    $stmtGet = $pdo->prepare("SELECT html_content, version FROM propuestas WHERE id = ?");
                    $stmtGet->execute([$id]);
                    $oldData = $stmtGet->fetch();
                    if ($oldData) {
                        $insertHistory = $pdo->prepare("INSERT INTO propuestas_history (propuesta_id, version, html_content) VALUES (?, ?, ?)");
                        $insertHistory->execute([$id, $oldData['version'], $oldData['html_content']]);
                    }
                }
                $stmt = $pdo->prepare("UPDATE propuestas SET slug = :slug, client_name = :name, pin = :pin, html_content = :html, sent_date = :sent_date, version = :version, equipo_ids = :equipo_ids WHERE id = :id");
                $stmt->execute([':slug' => $slug, ':name' => $client_name, ':pin' => $pin, ':html' => $html_content, ':sent_date' => $sent_date ?: null, ':version' => $version, ':equipo_ids' => $equipo_ids, ':id' => $id]);
                $success_msg = "Propuesta actualizada.";
            }
            else {
                $stmt = $pdo->prepare("INSERT INTO propuestas (slug, client_name, pin, html_content, sent_date, version, equipo_ids) VALUES (:slug, :name, :pin, :html, :sent_date, :version, :equipo_ids)");
                $stmt->execute([':slug' => $slug, ':name' => $client_name, ':pin' => $pin, ':html' => $html_content, ':sent_date' => $sent_date ?: null, ':version' => $version, ':equipo_ids' => $equipo_ids]);
                $success_msg = "Propuesta creada.";
            }
        }
        catch (PDOException $e) {
            $error_msg = ($e->getCode() == 23000) ? "Error: URL duplicada." : $e->getMessage();
        }
    }

    // Load Metrics
    $total_proposals = $pdo->query("SELECT COUNT(*) FROM propuestas WHERE status = 1")->fetchColumn();
    $total_views = $pdo->query("SELECT SUM(views_count) FROM propuestas")->fetchColumn() ?: 0;
    $proposals = $pdo->query("SELECT * FROM propuestas ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

    // Load approvals
    $appRows = $pdo->query("SELECT propuesta_id, tipo, aprobado_at FROM aprobaciones ORDER BY aprobado_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $approvals_map = [];
    foreach ($appRows as $a) {
        $approvals_map[$a['propuesta_id']][$a['tipo']] = $a['aprobado_at'];
    }

    // Load feedback
    $fbRows = $pdo->query("SELECT propuesta_id, comentario, created_at FROM feedback_presupuesto ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $feedback_map = [];
    foreach ($fbRows as $f) {
        $feedback_map[$f['propuesta_id']][] = $f;
    }

    // Load team
    $team = $pdo->query("SELECT * FROM equipo ORDER BY orden ASC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="es" class="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin | Tres Puntos</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Plus+Jakarta+Sans:wght@600;700&display=swap"
        rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        'tp-primary': '#5DFFBF',
                        'tp-primary-hover': '#49E6A8',
                        'tp-primary-active': '#3BD997',
                        'bg-base': '#0E0E0E',
                        'bg-surface': '#141414',
                        'bg-subtle': '#191919',
                        'text-primary': '#FFFFFF',
                        'text-secondary': '#8A8A8A',
                        'text-muted': '#5A5A5A',
                        'border-base': '#2A2A2A',
                        'border-subtle': '#1F1F1F',
                        'border-strong': '#3D3D3D',
                        'border-focus': '#5DFFBF',
                    },
                    fontFamily: {
                        body: ['Inter', 'sans-serif'],
                        heading: ['Plus Jakarta Sans', 'sans-serif'],
                    },
                    boxShadow: {
                        'surface': '0 4px 12px -2px rgba(0, 0, 0, 0.5)',
                    }
                }
            }
        }
    </script>
    <style>
        body {
            background-color: #0E0E0E;
            color: #FFFFFF;
            font-family: 'Inter', sans-serif;
        }

        h1,
        h2,
        h3,
        .font-heading {
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        .bg-gradient-tp-animated {
            background: linear-gradient(to right, #5DFFBF, #49E6A8, #FFFFFF, #5DFFBF);
            background-size: 300% auto;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: gradient-flow 5s linear infinite;
        }

        @keyframes gradient-flow {
            to {
                background-position: 300% center;
            }
        }

        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #0E0E0E;
        }

        ::-webkit-scrollbar-thumb {
            background: #2A2A2A;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #3D3D3D;
        }

        /* Custom Drawer / Modal System */
        .drawer {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 100;
            visibility: hidden;
            pointer-events: none;
            transition: visibility 0.3s;
        }

        .drawer.active {
            visibility: visible;
            pointer-events: auto;
        }

        .drawer-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(4px);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .drawer.active .drawer-backdrop {
            opacity: 1;
        }

        .drawer-content {
            position: absolute;
            top: 0;
            right: 0;
            height: 100%;
            width: 100%;
            max-width: 640px;
            background: #141414;
            border-left: 1px solid #1F1F1F;
            transform: translateX(100%);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            box-shadow: -20px 0 50px rgba(0, 0, 0, 0.5);
            overflow: hidden;
        }

        .drawer.active .drawer-content {
            transform: translateX(0);
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/preline/dist/preline.min.js" defer></script>
</head>

<body
    class="bg-bg-base text-text-primary antialiased min-h-screen flex flex-col selection:bg-tp-primary selection:text-bg-base">

    <?php if (!$is_logged_in): ?>
    <main class="w-full max-w-md mx-auto p-6 flex flex-col justify-center min-h-screen">
        <div class="bg-bg-surface border border-border-subtle rounded-2xl shadow-surface p-8">
            <div class="text-center mb-8">
                <img src="logo.svg" alt="Tres Puntos" class="h-10 w-auto mx-auto mb-6">
                <h1 class="text-2xl font-heading font-bold text-white">Proposal CRM</h1>
                <p class="mt-2 text-sm text-text-secondary">Acceso restringido para el equipo</p>
            </div>
            <form method="POST">
                <div class="grid gap-y-4">
                    <div>
                        <label for="password" class="block text-sm mb-2 font-medium text-text-secondary">Contraseña
                            Maestra</label>
                        <input type="password" id="password" name="password"
                            class="bg-bg-base border border-border-base text-white rounded-lg focus:border-border-focus focus:ring-1 focus:ring-border-focus transition-all w-full px-4 py-3 outline-none"
                            required>
                    </div>
                    <?php if ($error_msg): ?>
                    <p class="text-red-400 text-xs mt-1">
                        <?php echo $error_msg; ?>
                    </p>
                    <?php
    endif; ?>
                    <button type="submit" name="login"
                        class="w-full bg-tp-primary text-bg-base font-bold hover:bg-tp-primary-hover transition-colors rounded-xl py-3.5 mt-2 flex items-center justify-center gap-2">
                        <span>Iniciar Sesión</span>
                        <i data-lucide="arrow-right" class="w-4 h-4"></i>
                    </button>
                </div>
            </form>
        </div>
    </main>
    <?php
else: ?>
    <header
        class="sticky top-0 inset-x-0 z-50 w-full bg-bg-surface/80 backdrop-blur-md border-b border-border-subtle py-4">
        <nav class="max-w-[85rem] w-full mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between">
            <a href="admin.php">
                <img src="logo.svg" alt="Tres Puntos" class="h-6 w-auto">
            </a>
            <div class="flex items-center gap-6">
                <span class="text-xs text-text-muted hidden sm:inline-block">Admin Panel v2.0</span>
                <a class="text-sm font-medium text-text-secondary hover:text-white transition-colors"
                    href="?logout=1">Cerrar Sesión</a>
            </div>
        </nav>
    </header>

    <main class="max-w-[85rem] w-full mx-auto px-4 sm:px-6 lg:px-8 py-10 flex-grow">
        <?php if (!empty($error_msg)): ?>
        <div class="bg-red-500/10 border border-red-500/20 text-red-400 text-sm rounded-lg p-4 mb-6" role="alert">
            <?php echo htmlspecialchars($error_msg); ?>
        </div>
        <?php
    endif; ?>
        <?php if (isset($success_msg)): ?>
        <div class="bg-tp-primary/10 border border-tp-primary/20 text-tp-primary text-sm rounded-lg p-4 mb-6"
            role="alert">
            <?php echo htmlspecialchars($success_msg); ?>
        </div>
        <?php
    endif; ?>

        <div class="grid sm:grid-cols-2 gap-6 mb-10">
            <div class="bg-bg-surface border border-border-subtle rounded-2xl p-6 shadow-surface">
                <p class="text-xs uppercase tracking-widest text-text-muted font-bold mb-2">Propuestas Activas</p>
                <div class="flex items-end gap-2">
                    <h3 class="text-4xl font-heading font-bold text-white">
                        <?php echo $total_proposals; ?>
                    </h3>
                    <span class="text-tp-primary text-xs mb-1 font-medium italic">Online</span>
                </div>
            </div>
            <div class="bg-bg-surface border border-border-subtle rounded-2xl p-6 shadow-surface">
                <p class="text-xs uppercase tracking-widest text-text-muted font-bold mb-2">Visualizaciones Totales</p>
                <div class="flex items-end gap-2">
                    <h3 class="text-4xl font-heading font-bold text-white">
                        <?php echo number_format($total_views); ?>
                    </h3>
                    <i data-lucide="eye" class="w-5 h-5 mb-2 ml-1 text-text-muted"></i>
                </div>
            </div>
        </div>

        <!-- View Selector Tabs -->
        <div class="flex gap-4 mb-4 border-b border-border-subtle">
            <button onclick="switchView('proposals')" id="tab-proposals"
                class="px-6 py-3 text-sm font-bold border-b-2 border-tp-primary text-tp-primary transition-all">Propuestas</button>
            <button onclick="switchView('team')" id="tab-team"
                class="px-6 py-3 text-sm font-bold border-b-2 border-transparent text-text-muted hover:text-white transition-all">Equipo</button>
        </div>

        <!-- Proposals Section -->
        <div id="section-proposals">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
                <h2 class="text-2xl font-heading font-bold text-white">Directorio Comercial</h2>
                <button type="button"
                    class="bg-tp-primary text-bg-base font-bold hover:bg-tp-primary-hover transition-all rounded-xl py-2.5 px-6 flex items-center justify-center gap-2 shadow-[0_0_20px_rgba(93,255,191,0.15)]"
                    onclick="openNewProposalModal()">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                    Nueva Propuesta
                </button>
            </div>


            <div class="bg-bg-surface border border-border-subtle rounded-2xl shadow-surface overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-border-subtle">
                        <thead class="bg-bg-subtle/50">
                            <tr>
                                <th
                                    class="px-6 py-4 text-start text-xs font-bold text-text-muted uppercase tracking-wider">
                                    Cliente</th>
                                <th
                                    class="px-6 py-4 text-start text-xs font-bold text-text-muted uppercase tracking-wider">
                                    URL / Slug</th>
                                <th
                                    class="px-6 py-4 text-start text-xs font-bold text-text-muted uppercase tracking-wider">
                                    PIN / Versión</th>
                                <th
                                    class="px-6 py-4 text-start text-xs font-bold text-text-muted uppercase tracking-wider">
                                    Actualización</th>
                                <th
                                    class="px-6 py-4 text-center text-xs font-bold text-text-muted uppercase tracking-wider">
                                    Aprobaciones</th>
                                <th
                                    class="px-6 py-4 text-center text-xs font-bold text-text-muted uppercase tracking-wider">
                                    Presupuesto</th>
                                <th
                                    class="px-6 py-4 text-start text-xs font-bold text-text-muted uppercase tracking-wider">
                                    Estado</th>
                                <th
                                    class="px-6 py-4 text-center text-xs font-bold text-text-muted uppercase tracking-wider">
                                    Vistas</th>
                                <th
                                    class="px-6 py-4 text-end text-xs font-bold text-text-muted uppercase tracking-wider">
                                    Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-subtle">
                            <?php if (empty($proposals)): ?>
                            <tr>
                                <td colspan="9" class="px-6 py-12 text-center text-text-muted italic">No hay propuestas
                                    registradas.</td>
                            </tr>
                            <?php
    else:
        foreach ($proposals as $p): ?>
                            <tr class="hover:bg-bg-subtle/30 transition-colors">
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <div class="text-sm font-semibold text-white">
                                        <?php echo htmlspecialchars($p['client_name']); ?>
                                    </div>
                                    <div class="text-[10px] text-text-muted mt-1 uppercase tracking-tighter">
                                        <?php echo $p['last_accessed_at'] ? 'Visto: ' . date('d/m/y H:i', strtotime($p['last_accessed_at'])) : 'Sin aperturas'; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <div class="flex items-center gap-2 group">
                                        <a href="<?php echo htmlspecialchars($base_path); ?>/p/<?php echo htmlspecialchars($p['slug']); ?>"
                                            target="_blank"
                                            class="text-xs font-mono text-text-secondary hover:text-white group-hover:text-tp-primary transition-colors flex items-center gap-1.5">
                                            <?php echo htmlspecialchars($base_path); ?>/p/
                                            <?php echo htmlspecialchars($p['slug']); ?>
                                            <i data-lucide="external-link"
                                                class="w-3 h-3 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                                        </a>
                                        <button
                                            onclick="copyToClipboard('<?php echo 'https://' . $_SERVER['HTTP_HOST'] . $base_path . '/p/' . $p['slug']; ?>')"
                                            class="text-text-muted hover:text-white opacity-0 group-hover:opacity-100 transition-all ml-1">
                                            <i data-lucide="copy" class="w-3.5 h-3.5"></i>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="bg-bg-base border border-border-base px-2 py-1 rounded-md font-mono text-xs text-tp-primary">
                                            <?php echo htmlspecialchars($p['pin']); ?>
                                        </span>
                                        <span class="text-xs text-text-muted">/</span>
                                        <span class="text-xs font-semibold text-white">
                                            <?php echo htmlspecialchars($p['version'] ?? 'v1.0'); ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <span class="text-xs text-text-secondary">
                                        <?php echo $p['sent_date'] ? date('d/m/y', strtotime($p['sent_date'])) : '--/--/--'; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap text-center">
                                    <div class="flex flex-col items-center gap-2">
                                        <?php
            $doc_approved = isset($approvals_map[$p['id']]['documento_funcional']);
            $pres_approved = isset($approvals_map[$p['id']]['presupuesto']);
?>
                                        <!-- Documento Toggle -->
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="text-[9px] font-bold uppercase tracking-wider <?php echo $doc_approved ? 'text-tp-primary' : 'text-text-muted'; ?>">Doc</span>
                                            <label class="relative inline-flex items-center cursor-pointer">
                                                <input type="checkbox" class="sr-only peer"
                                                    onchange="toggleApproval(<?php echo $p['id']; ?>, 'documento_funcional', this.checked, this)"
                                                    <?php echo $doc_approved ? 'checked' : ''; ?>>
                                                <div
                                                    class="w-7 h-4 bg-bg-base border border-border-base rounded-full peer peer-checked:after:translate-x-[12px] after:content-[''] after:absolute after:top-[3px] after:left-[3px] after:bg-text-muted after:rounded-full after:h-2 after:w-2 after:transition-all peer-checked:after:bg-bg-base peer-checked:bg-tp-primary peer-checked:border-tp-primary">
                                                </div>
                                            </label>
                                        </div>

                                        <!-- Presupuesto Toggle -->
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="text-[9px] font-bold uppercase tracking-wider <?php echo $pres_approved ? 'text-tp-primary' : 'text-text-muted'; ?>">Pres</span>
                                            <label class="relative inline-flex items-center cursor-pointer">
                                                <input type="checkbox" class="sr-only peer"
                                                    onchange="toggleApproval(<?php echo $p['id']; ?>, 'presupuesto', this.checked, this)"
                                                    <?php echo $pres_approved ? 'checked' : ''; ?>>
                                                <div
                                                    class="w-7 h-4 bg-bg-base border border-border-base rounded-full peer peer-checked:after:translate-x-[12px] after:content-[''] after:absolute after:top-[3px] after:left-[3px] after:bg-text-muted after:rounded-full after:h-2 after:w-2 after:transition-all peer-checked:after:bg-bg-base peer-checked:bg-tp-primary peer-checked:border-tp-primary">
                                                </div>
                                            </label>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap text-center">
                                    <?php if (!empty($p['presupuesto_pdf'])): ?>
                                    <div class="flex items-center justify-center gap-2">
                                        <span
                                            class="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-tp-primary">
                                            <i data-lucide="file-text" class="w-3 h-3"></i> PDF
                                        </span>
                                        <button onclick="confirmDeletePdf(<?php echo $p['id']; ?>)"
                                            class="text-red-500/60 hover:text-red-400 transition-colors"
                                            title="Borrar PDF">
                                            <i data-lucide="trash-2" class="w-3 h-3"></i>
                                        </button>
                                    </div>
                                    <?php
            else: ?>
                                    <button
                                        onclick="openUploadPDF(<?php echo $p['id']; ?>, '<?php echo addslashes($p['client_name']); ?>')"
                                        class="text-[10px] font-bold uppercase tracking-wider text-tp-primary hover:text-white transition-colors flex items-center gap-1 mx-auto">
                                        <i data-lucide="upload-cloud" class="w-3 h-3"></i> Subir
                                    </button>
                                    <?php
            endif; ?>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" class="sr-only peer"
                                            onchange="toggleStatus(<?php echo $p['id']; ?>, this.checked)" <?php echo
                $p['status'] == 1 ? 'checked' : ''; ?>>
                                        <div
                                            class="w-10 h-5 bg-bg-base border border-border-base rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[4px] after:left-[4px] after:bg-text-muted after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:after:bg-bg-base peer-checked:bg-tp-primary peer-checked:border-tp-primary">
                                        </div>
                                    </label>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap font-heading font-bold text-white text-center">
                                    <?php echo $p['views_count']; ?>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap text-end text-sm">
                                    <div class="flex items-center justify-end gap-3">
                                        <button onclick="editProposal(JSON.parse(this.dataset.proposal))"
                                            data-proposal="<?php echo htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8'); ?>"
                                            class="text-text-secondary hover:text-tp-primary transition-colors p-1"
                                            title="Editar">
                                            <i data-lucide="edit-3" class="w-4 h-4"></i>
                                        </button>
                                        <button
                                            onclick="confirmDeleteProposal(<?php echo $p['id']; ?>, <?php echo htmlspecialchars(json_encode($p['client_name']), ENT_QUOTES, 'UTF-8'); ?>)"
                                            class="text-red-500/60 hover:text-red-400 transition-colors p-1"
                                            title="Borrar Propuesta">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php
        endforeach;
    endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Team Management Section -->
        <div id="section-team" class="hidden">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
                <h2 class="text-2xl font-heading font-bold text-white">Gestión de Equipo</h2>
                <button type="button" onclick="openMemberModal()"
                    class="bg-tp-primary text-bg-base font-bold hover:bg-tp-primary-hover transition-all rounded-xl py-2.5 px-6 flex items-center justify-center gap-2 shadow-[0_0_20px_rgba(93,255,191,0.15)]">
                    <i data-lucide="plus-circle" class="w-4 h-4"></i>
                    Añadir Miembro
                </button>
            </div>

            <div class="bg-bg-surface border border-border-subtle rounded-2xl shadow-surface overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-border-subtle">
                        <thead class="bg-bg-subtle/50">
                            <tr>
                                <th
                                    class="px-6 py-4 text-start text-xs font-bold text-text-muted uppercase tracking-wider">
                                    Miembro</th>
                                <th
                                    class="px-6 py-4 text-start text-xs font-bold text-text-muted uppercase tracking-wider">
                                    Cargo</th>
                                <th
                                    class="px-6 py-4 text-start text-xs font-bold text-text-muted uppercase tracking-wider">
                                    Descripción</th>
                                <th
                                    class="px-6 py-4 text-center text-xs font-bold text-text-muted uppercase tracking-wider">
                                    Orden</th>
                                <th
                                    class="px-6 py-4 text-end text-xs font-bold text-text-muted uppercase tracking-wider">
                                    Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-subtle">
                            <?php if (empty($team)): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center text-text-muted italic">No hay miembros en
                                    el equipo.</td>
                            </tr>
                            <?php
    else:
        foreach ($team as $m): ?>
                            <tr class="hover:bg-bg-subtle/30 transition-colors">
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="w-10 h-10 rounded-full bg-border-base overflow-hidden flex-shrink-0">
                                            <?php if ($m['foto_url']): ?>
                                            <img src="<?php echo htmlspecialchars($m['foto_url']); ?>"
                                                class="w-full h-full object-cover">
                                            <?php
            else: ?>
                                            <div class="w-full h-full flex items-center justify-center text-text-muted">
                                                <i data-lucide="user" class="w-5 h-5"></i>
                                            </div>
                                            <?php
            endif; ?>
                                        </div>
                                        <div class="text-sm font-semibold text-white">
                                            <?php echo htmlspecialchars($m['nombre']); ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap text-sm text-text-secondary">
                                    <?php echo htmlspecialchars($m['cargo']); ?>
                                </td>
                                <td class="px-6 py-5 text-xs text-text-muted max-w-xs truncate">
                                    <?php echo htmlspecialchars($m['descripcion']); ?>
                                </td>
                                <td class="px-6 py-5 text-center text-sm font-mono text-tp-primary">
                                    <?php echo $m['orden']; ?>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap text-end text-sm">
                                    <div class="flex items-center justify-end gap-3">
                                        <button onclick="editMember(JSON.parse(this.dataset.member))"
                                            data-member="<?php echo htmlspecialchars(json_encode($m), ENT_QUOTES, 'UTF-8'); ?>"
                                            class="text-text-secondary hover:text-tp-primary p-1"
                                            title="Editar Miembro">
                                            <i data-lucide="edit-3" class="w-4 h-4"></i>
                                        </button>
                                        <button
                                            onclick="confirmDeleteMember(<?php echo $m['id']; ?>, '<?php echo addslashes($m['nombre']); ?>')"
                                            class="text-red-500/60 hover:text-red-400 p-1"><i data-lucide="trash-2"
                                                class="w-4 h-4"></i></button>
                                    </div>
                                </td>
                            </tr>
                            <?php
        endforeach;
    endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- Overlay Sidebar -->
    <div id="hs-overlay-create" class="drawer">
        <div class="drawer-backdrop" onclick="closeDrawer('hs-overlay-create')"></div>
        <div class="drawer-content">
            <div class="flex justify-between items-center py-5 px-8 border-b border-border-subtle">
                <h3 class="text-xl font-heading font-bold text-white" id="modal-title">Nueva Propuesta</h3>
                <button type="button" class="text-text-muted hover:text-white transition-colors"
                    onclick="closeDrawer('hs-overlay-create')"><i data-lucide="x" class="w-6 h-6"></i></button>
            </div>
            <div class="p-8 overflow-y-auto flex-1 custom-scrollbar">
                <form method="POST" id="proposal-form" class="space-y-6">
                    <input type="hidden" name="id" id="form-id" value="">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-semibold text-text-secondary mb-2">Cliente</label>
                            <input type="text" id="client_name" name="client_name"
                                class="bg-bg-base border border-border-base text-white rounded-xl focus:border-border-focus focus:ring-1 focus:ring-border-focus transition-all w-full px-4 py-3 outline-none"
                                required>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-text-secondary mb-2">URL Slug</label>
                            <input type="text" id="slug" name="slug"
                                class="bg-bg-base border border-border-base text-white rounded-xl focus:border-border-focus focus:ring-1 focus:ring-border-focus transition-all w-full px-4 py-3 font-mono outline-none"
                                required pattern="[a-zA-Z0-9_-]+">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-semibold text-text-secondary mb-2">Fecha Envío</label>
                            <input type="date" id="sent_date" name="sent_date"
                                class="bg-bg-base border border-border-base text-white rounded-xl focus:border-border-focus focus:ring-1 focus:ring-border-focus transition-all w-full px-4 py-3 outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-text-secondary mb-2">Versión</label>
                            <input type="text" id="version" name="version"
                                class="bg-bg-base border border-border-base text-white rounded-xl focus:border-border-focus focus:ring-1 focus:ring-border-focus transition-all w-full px-4 py-3 font-mono outline-none"
                                placeholder="v1.0">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-semibold text-text-secondary mb-2">PIN Acceso</label>
                            <input type="text" id="pin" name="pin"
                                class="bg-bg-base border border-border-base text-tp-primary rounded-xl focus:border-border-focus focus:ring-1 focus:ring-border-focus transition-all w-full px-4 py-3 font-mono text-lg tracking-widest outline-none"
                                required>
                        </div>
                        <div class="flex items-end pb-3">
                            <label class="flex items-center space-x-3 cursor-pointer">
                                <input type="checkbox" name="new_version" id="new_version" value="1"
                                    class="w-5 h-5 rounded border-border-base bg-bg-base text-tp-primary focus:ring-tp-primary focus:ring-offset-bg-surface">
                                <span class="text-sm font-semibold text-text-secondary">Guardar como nueva
                                    versión</span>
                            </label>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-text-secondary mb-2">Equipo Asignado</label>
                        <div
                            class="bg-bg-base border border-border-base rounded-xl p-4 max-h-48 overflow-y-auto custom-scrollbar space-y-2">
                            <?php if (empty($team)): ?>
                            <p class="text-xs text-text-muted italic">No hay miembros del equipo registrados.</p>
                            <?php
    else: ?>
                            <?php foreach ($team as $m): ?>
                            <label
                                class="flex items-center space-x-3 cursor-pointer p-2 hover:bg-bg-surface rounded-lg transition-colors">
                                <input type="checkbox" name="equipo_ids[]" id="equipo_<?php echo $m['id']; ?>"
                                    value="<?php echo $m['id']; ?>"
                                    class="w-4 h-4 rounded border-border-base bg-bg-base text-tp-primary focus:ring-tp-primary focus:ring-offset-bg-surface">
                                <div class="flex items-center gap-3">
                                    <?php if ($m['foto_url']): ?>
                                    <img src="<?php echo htmlspecialchars($m['foto_url']); ?>"
                                        class="w-6 h-6 rounded-full object-cover border border-border-subtle">
                                    <?php
            else: ?>
                                    <div
                                        class="w-6 h-6 rounded-full bg-bg-surface flex items-center justify-center text-text-muted border border-border-subtle">
                                        <i data-lucide="user" class="w-3 h-3"></i>
                                    </div>
                                    <?php
            endif; ?>
                                    <span class="text-sm text-text-primary">
                                        <?php echo htmlspecialchars($m['nombre']); ?> <span
                                            class="text-xs text-text-muted ml-1">(
                                            <?php echo htmlspecialchars($m['cargo']); ?>)
                                        </span>
                                    </span>
                                </div>
                            </label>
                            <?php
        endforeach; ?>
                            <?php
    endif; ?>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <label class="block text-sm font-semibold text-text-secondary">Contenido HTML</label>
                            <button type="button" onclick="document.getElementById('file_upload').click()"
                                class="text-xs bg-tp-primary/10 text-tp-primary border border-tp-primary/30 px-3 py-1.5 rounded hover:bg-tp-primary/20 transition-colors flex items-center gap-2">
                                <i data-lucide="upload" class="w-4 h-4"></i> Subir HTML
                            </button>
                            <input type="file" id="file_upload" accept=".html,.htm" class="hidden"
                                onchange="handleFileUpload(event)">
                        </div>
                        <textarea id="html_content" name="html_content" rows="12"
                            class="bg-bg-base border border-border-base text-slate-300 rounded-xl focus:border-border-focus focus:ring-1 focus:ring-border-focus transition-all w-full px-4 py-4 font-mono text-xs whitespace-pre outline-none resize-none"
                            required></textarea>
                    </div>
                    <div class="flex gap-4 pt-4">
                        <button type="submit" name="save_proposal"
                            class="flex-1 bg-tp-primary text-bg-base font-bold hover:bg-tp-primary-hover transition-colors rounded-xl py-4 shadow-lg">Publicar
                            Propuesta</button>
                        <button type="button"
                            class="bg-bg-subtle text-white px-8 rounded-xl border border-border-base hover:bg-border-strong transition-colors"
                            onclick="closeDrawer('hs-overlay-create')">Salir</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Dynamic Team Member Modal -->
    <div id="hs-overlay-member" class="drawer">
        <div class="drawer-backdrop" onclick="closeDrawer('hs-overlay-member')"></div>
        <div class="drawer-content">
            <div class="flex justify-between items-center py-5 px-8 border-b border-border-subtle">
                <h3 class="text-xl font-heading font-bold text-white shadow-sm" id="member-modal-title">Miembro del
                    Equipo</h3>
                <button type="button"
                    class="flex justify-center items-center w-8 h-8 rounded-full border border-border-base bg-bg-subtle text-text-muted hover:text-white transition-all shadow-sm"
                    onclick="closeDrawer('hs-overlay-member')">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>
            <div class="p-8 overflow-y-auto flex-1 custom-scrollbar">
                <form id="member-form" enctype="multipart/form-data">
                    <input type="hidden" name="api_action" value="save_member">
                    <input type="hidden" id="member-id" name="id" value="">
                    <input type="hidden" id="current_foto" name="current_foto" value="">

                    <div class="space-y-6">
                        <div>
                            <label class="block text-sm font-semibold text-text-secondary mb-2">Nombre Completo</label>
                            <input type="text" id="member-nombre" name="nombre"
                                class="bg-bg-base border border-border-base text-white rounded-xl focus:border-border-focus focus:ring-1 focus:ring-border-focus transition-all w-full px-4 py-3 outline-none"
                                placeholder="Ej: Jordi TresPuntos" required>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-text-secondary mb-2">Cargo /
                                Especialidad</label>
                            <input type="text" id="member-cargo" name="cargo"
                                class="bg-bg-base border border-border-base text-white rounded-xl focus:border-border-focus focus:ring-1 focus:ring-border-focus transition-all w-full px-4 py-3 outline-none"
                                placeholder="Ej: Lead Designer">
                        </div>
                        <div>
                            <label
                                class="block text-sm font-semibold text-text-secondary mb-2 text-balance leading-relaxed">Breve
                                Descripción</label>
                            <textarea id="member-descripcion" name="descripcion" rows="3"
                                class="bg-bg-base border border-border-base text-white rounded-xl focus:border-border-focus focus:ring-1 focus:ring-border-focus transition-all w-full px-4 py-3 outline-none resize-none"
                                placeholder="Breve biografía o responsabilidades..."></textarea>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-text-secondary mb-2">Orden Visual</label>
                                <input type="number" id="member-orden" name="orden" value="0"
                                    class="bg-bg-base border border-border-base text-white rounded-xl focus:border-border-focus focus:ring-1 focus:ring-border-focus transition-all w-full px-4 py-3 outline-none">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-text-secondary mb-2">Fotografía</label>
                            <div class="bg-bg-base/50 border border-border-base rounded-2xl p-6">
                                <div class="flex flex-col sm:flex-row items-center gap-6">
                                    <div id="member-photo-preview"
                                        class="w-24 h-24 rounded-2xl bg-bg-base border-2 border-dashed border-border-base overflow-hidden flex items-center justify-center text-text-muted transition-all">
                                        <i data-lucide="user" class="w-10 h-10"></i>
                                    </div>
                                    <div class="flex-1 text-center sm:text-left">
                                        <div class="relative group cursor-pointer inline-block w-full">
                                            <input type="file" name="foto" id="member-foto" accept="image/*"
                                                class="absolute inset-0 opacity-0 cursor-pointer z-10"
                                                onchange="previewPhoto(event)">
                                            <div
                                                class="bg-tp-primary/10 text-tp-primary border border-tp-primary/20 px-4 py-2.5 rounded-xl text-sm font-bold group-hover:bg-tp-primary/20 transition-all flex items-center justify-center gap-2">
                                                <i data-lucide="image-plus" class="w-4 h-4"></i>
                                                <span>Seleccionar Imagen</span>
                                            </div>
                                        </div>
                                        <p class="text-[10px] text-text-muted mt-3 leading-relaxed">Formatos: JPG, PNG,
                                            WEBP. <br>Recomendado: Cuadrado 500x500px.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-10 flex gap-4">
                        <button type="submit"
                            class="flex-1 bg-tp-primary text-bg-base font-bold hover:bg-tp-primary-hover transition-colors rounded-xl py-4 shadow-lg flex items-center justify-center gap-2">
                            <i data-lucide="save" class="w-4 h-4"></i> Guardar Cambios
                        </button>
                        <button type="button" onclick="closeDrawer('hs-overlay-member')"
                            class="bg-bg-subtle text-white px-8 rounded-xl border border-border-base hover:bg-border-strong transition-colors">Salir</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Subir Presupuesto PDF -->
    <div id="pdf-upload-overlay"
        class="fixed inset-0 bg-black/80 backdrop-blur-sm z-[90] hidden items-center justify-center">
        <div class="bg-bg-surface border border-border-subtle rounded-2xl shadow-2xl p-8 max-w-md w-full mx-4">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg font-heading font-bold text-white" id="upload-modal-title">Subir Presupuesto PDF</h3>
                <button onclick="closeUploadPDF()" class="text-text-muted hover:text-white transition-colors"><i
                        data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <form id="pdf-upload-form" enctype="multipart/form-data">
                <input type="hidden" id="upload-propuesta-id" name="propuesta_id" value="">
                <div class="mb-6">
                    <label class="block text-sm font-semibold text-text-secondary mb-3">Archivo oficial (Holded)</label>
                    <input type="file" id="pdf_file_input" name="pdf_file" accept=".pdf"
                        class="bg-bg-base border border-border-base text-white rounded-xl w-full px-4 py-3 outline-none file:mr-4 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-bold file:bg-tp-primary/20 file:text-tp-primary hover:file:bg-tp-primary/30 cursor-pointer"
                        required>
                </div>
                <div class="flex gap-3">
                    <button type="submit"
                        class="flex-1 bg-tp-primary text-bg-base font-bold hover:bg-tp-primary-hover transition-colors rounded-xl py-3 flex items-center justify-center gap-2">
                        <i data-lucide="upload-cloud" class="w-4 h-4"></i> Subir PDF
                    </button>
                    <button type="button" onclick="closeUploadPDF()"
                        class="bg-bg-subtle text-white px-6 rounded-xl border border-border-base hover:bg-border-strong transition-colors">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Custom Delete Confirmation Modal -->
    <div id="delete-confirm-overlay"
        class="fixed inset-0 bg-black/80 backdrop-blur-sm z-[90] hidden items-center justify-center">
        <div class="bg-bg-surface border border-border-subtle rounded-2xl shadow-2xl p-8 max-w-md w-full mx-4">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg font-heading font-bold text-white">Confirmar Eliminación</h3>
                <button onclick="closeDeleteConfirm()" class="text-text-muted hover:text-white transition-colors"><i
                        data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <p id="delete-confirm-text" class="text-text-secondary mb-6 text-sm"></p>
            <div class="flex gap-3">
                <button id="btn-confirm-delete"
                    class="flex-1 bg-red-500 text-white font-bold hover:bg-red-600 transition-colors rounded-xl py-3 text-sm flex items-center justify-center gap-2">
                    <i data-lucide="trash-2" class="w-4 h-4"></i> Confirmar
                </button>
                <button type="button" onclick="closeDeleteConfirm()"
                    class="bg-bg-subtle text-white px-6 rounded-xl border border-border-base hover:bg-border-strong transition-colors">Cancelar</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/preline/dist/preline.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/lucide/dist/umd/lucide.min.js"></script>
    <script>
        lucide.createIcons();

        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => alert('Enlace copiado'));
        }

        async function toggleStatus(id, active) {
            await fetch('admin.php?action=toggle_status', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, status: active ? 1 : 0 })
            });
        }

        async function toggleApproval(propuesta_id, tipo, active, el) {
            const res = await fetch('admin.php?action=toggle_approval', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ propuesta_id, tipo, status: active ? 1 : 0 })
            });
            const data = await res.json();
            if (data.success) {
                // Actualizar el color del label dinámicamente
                const label = el.closest('.flex').querySelector('span');
                if (active) {
                    label.classList.remove('text-text-muted');
                    label.classList.add('text-tp-primary');
                } else {
                    label.classList.remove('text-tp-primary');
                    label.classList.add('text-text-muted');
                }
            }
        }

        function switchView(view) {
            const sectionProposals = document.getElementById('section-proposals');
            const sectionTeam = document.getElementById('section-team');
            const tabProposals = document.getElementById('tab-proposals');
            const tabTeam = document.getElementById('tab-team');

            if (view === 'proposals') {
                sectionProposals.classList.remove('hidden');
                sectionTeam.classList.add('hidden');
                tabProposals.className = "px-6 py-3 text-sm font-bold border-b-2 border-tp-primary text-tp-primary transition-all";
                tabTeam.className = "px-6 py-3 text-sm font-bold border-b-2 border-transparent text-text-muted hover:text-white transition-all";
            } else {
                sectionProposals.classList.add('hidden');
                sectionTeam.classList.remove('hidden');
                tabTeam.className = "px-6 py-3 text-sm font-bold border-b-2 border-tp-primary text-tp-primary transition-all";
                tabProposals.className = "px-6 py-3 text-sm font-bold border-b-2 border-transparent text-text-muted hover:text-white transition-all";
                if (window.lucide) lucide.createIcons();
            }
        }

        let pendingDeleteId = null;
        let deleteType = null; // 'proposal', 'pdf', or 'member'

        function confirmDeleteProposal(id, name) {
            pendingDeleteId = id;
            deleteType = 'proposal';
            document.getElementById('delete-confirm-text').innerText = `Vas a eliminar la propuesta de "${name}" y todo su historial. Esta acción no se puede deshacer.`;
            document.getElementById('btn-confirm-delete').className = "flex-1 bg-red-500 text-white font-bold hover:bg-red-600 transition-colors rounded-xl py-3 text-sm flex items-center justify-center gap-2";
            document.getElementById('delete-confirm-overlay').style.display = 'flex';
        }

        function confirmDeletePdf(id) {
            pendingDeleteId = id;
            deleteType = 'pdf';
            document.getElementById('delete-confirm-text').innerText = `¿Deseas eliminar el PDF del presupuesto subido? Deberás subir uno nuevo para que el cliente lo vea.`;
            document.getElementById('btn-confirm-delete').className = "flex-1 bg-red-500 text-white font-bold hover:bg-red-600 transition-colors rounded-xl py-3 text-sm flex items-center justify-center gap-2";
            document.getElementById('delete-confirm-overlay').style.display = 'flex';
        }

        function confirmDeleteMember(id, name) {
            pendingDeleteId = id;
            deleteType = 'member';
            document.getElementById('delete-confirm-text').innerText = `¿Deseas eliminar a ${name} del equipo? Esta acción no se puede deshacer.`;
            document.getElementById('btn-confirm-delete').className = "flex-1 bg-red-500 text-white font-bold hover:bg-red-600 transition-colors rounded-xl py-3 text-sm flex items-center justify-center gap-2";
            document.getElementById('delete-confirm-overlay').style.display = 'flex';
        }

        function closeDeleteConfirm() {
            document.getElementById('delete-confirm-overlay').style.display = 'none';
            pendingDeleteId = null;
            deleteType = null;
        }

        document.getElementById('btn-confirm-delete').onclick = async () => {
            if (deleteType === 'member') {
                const formData = new FormData();
                formData.append('id', pendingDeleteId);
                const res = await fetch('admin.php?action=delete_member', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) window.location.reload(); else alert(data.message);
                return;
            }

            const action = deleteType === 'proposal' ? 'delete' : 'delete_pdf';
            const res = await fetch(`admin.php?action=${action}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: pendingDeleteId })
            });
            const data = await res.json();
            if (data.success) window.location.reload(); else alert(data.message);
            closeDeleteConfirm();
        };

        function editProposal(p) {
            document.getElementById('modal-title').innerText = 'Editar Propuesta';
            document.getElementById('form-id').value = p.id;
            document.getElementById('client_name').value = p.client_name;
            document.getElementById('slug').value = p.slug;
            document.getElementById('pin').value = p.pin;
            document.getElementById('html_content').value = p.html_content;
            document.getElementById('sent_date').value = p.sent_date || '';
            document.getElementById('version').value = p.version || 'v1.0';
            openDrawer('hs-overlay-create');
        }

        function openDrawer(id) {
            document.getElementById(id).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeDrawer(id) {
            document.getElementById(id).classList.remove('active');
            document.body.style.overflow = '';
        }

        function openNewProposalModal() {
            document.getElementById('modal-title').innerText = 'Nueva Propuesta';
            document.getElementById('proposal-form').reset();
            document.getElementById('form-id').value = '';
            document.getElementById('html_content').value = '';
            openDrawer('hs-overlay-create');
        }

        function openMemberModal() {
            document.getElementById('member-modal-title').innerText = 'Añadir Miembro';
            document.getElementById('member-form').reset();
            document.getElementById('member-id').value = '';
            document.getElementById('current_foto').value = '';
            document.getElementById('member-photo-preview').innerHTML = '<i data-lucide="user" class="w-8 h-8"></i>';
            if (window.lucide) lucide.createIcons();
            openDrawer('hs-overlay-member');
        }

        function editMember(m) {
            document.getElementById('member-modal-title').innerText = 'Editar Miembro';
            document.getElementById('member-id').value = m.id;
            document.getElementById('member-nombre').value = m.nombre;
            document.getElementById('member-cargo').value = m.cargo || '';
            document.getElementById('member-descripcion').value = m.descripcion || '';
            document.getElementById('member-orden').value = m.orden || 0;
            document.getElementById('current_foto').value = m.foto_url || '';

            if (m.foto_url) {
                document.getElementById('member-photo-preview').innerHTML = `<img src="${m.foto_url}" class="w-full h-full object-cover">`;
            } else {
                document.getElementById('member-photo-preview').innerHTML = '<i data-lucide="user" class="w-8 h-8"></i>';
            }
            if (window.lucide) lucide.createIcons();
            openDrawer('hs-overlay-member');
        }

        document.getElementById('member-form').onsubmit = async (e) => {
            e.preventDefault();
            console.log("Submitting member form...");
            const body = new FormData(e.target);
            try {
                const res = await fetch('admin.php?action=save_member', { method: 'POST', body });
                console.log("Response status:", res.status);
                const data = await res.json();
                console.log("Response data:", data);
                if (data.success) window.location.reload();
                else alert("Error del servidor: " + (data.message || "Desconocido"));
            } catch (err) {
                console.error("Fetch error:", err);
                alert("Error de conexión al guardar.");
            }
        };

        function previewPhoto(event) {
            const file = event.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = (e) => {
                document.getElementById('member-photo-preview').innerHTML = `<img src="${e.target.result}" class="w-full h-full object-cover">`;
            };
            reader.readAsDataURL(file);
        }

        function handleFileUpload(e) {
            const file = e.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = (ev) => {
                document.getElementById('html_content').value = ev.target.result;
                document.getElementById('new_version').checked = true;
                const v = document.getElementById('version');
                const m = v.value.match(/v(\d+)\./);
                if (m) {
                    v.value = 'v' + (parseInt(m[1]) + 1) + '.0';
                } else {
                    v.value = 'v2.0';
                }
            };
            reader.readAsText(file);
        }

        function openUploadPDF(id, name) {
            document.getElementById('upload-propuesta-id').value = id;
            document.getElementById('upload-modal-title').innerText = 'Presupuesto: ' + name;
            document.getElementById('pdf-upload-overlay').style.display = 'flex';
        }

        function closeUploadPDF() {
            document.getElementById('pdf-upload-overlay').style.display = 'none';
        }

        document.getElementById('pdf-upload-form').onsubmit = async (e) => {
            e.preventDefault();
            const body = new FormData(e.target);
            const res = await fetch('admin.php?action=upload_presupuesto', { method: 'POST', body });
            const data = await res.json();
            if (data.success) window.location.reload(); else alert(data.message);
        };
    </script>
    <?php
endif; ?>
</body>

</html>