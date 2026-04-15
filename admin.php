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

    // Historial de versiones (API ADMIN)
    if ($_GET['action'] === 'get_history' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['id'])) {
            try {
                $stmt = $pdo->prepare("SELECT id, version, created_at FROM propuestas_history WHERE propuesta_id = ? ORDER BY created_at DESC");
                $stmt->execute([(int)$_GET['id']]);
                $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'history' => $history]);
            }
            catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        }
        exit;
    }

    if ($_GET['action'] === 'get_history_html' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['history_id'])) {
            try {
                $stmt = $pdo->prepare("SELECT html_content FROM propuestas_history WHERE id = ?");
                $stmt->execute([(int)$_GET['history_id']]);
                $html = $stmt->fetchColumn();
                echo json_encode(['success' => true, 'html' => $html]);
            }
            catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
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

    // ===== HOLDED — vincular presupuesto Holded a propuesta =====
    if (in_array($_GET['action'] ?? '', ['holded_search', 'holded_preview', 'holded_link', 'holded_sync', 'holded_unlink'], true)) {
        require_once __DIR__ . '/api/holded_client.php';
    }

    if ($_GET['action'] === 'holded_search' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $q = trim($_GET['q'] ?? '');
        $res = holded_search_estimates($q, 10);
        if (!$res['ok']) { echo json_encode(['success' => false, 'message' => $res['error'] ?? 'Error']); exit; }
        // Añadir formato legible en cada item
        $items = array_map(function ($i) {
            return $i + [
                'date_fmt'  => holded_format_date($i['date']),
                'total_fmt' => holded_format_currency($i['total']),
                'status_fmt'=> holded_status_label((int)($i['status'] ?? 0)),
            ];
        }, $res['items']);
        echo json_encode(['success' => true, 'items' => $items]);
        exit;
    }

    if ($_GET['action'] === 'holded_preview' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = trim($_GET['holded_id'] ?? '');
        $num = trim($_GET['doc_number'] ?? '');
        $doc = null;
        if ($id !== '') $doc = holded_get_estimate($id);
        elseif ($num !== '') $doc = holded_find_by_number($num);
        if (!$doc) { echo json_encode(['success' => false, 'message' => 'No encontrado en Holded']); exit; }
        echo json_encode(['success' => true, 'data' => $doc]);
        exit;
    }

    if ($_GET['action'] === 'holded_link' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $pid = (int)($data['propuesta_id'] ?? 0);
        $id = trim($data['holded_id'] ?? '');
        if (!$pid || !$id) { echo json_encode(['success' => false, 'message' => 'Faltan datos']); exit; }
        $doc = holded_get_estimate($id);
        if (!$doc) { echo json_encode(['success' => false, 'message' => 'Presupuesto Holded no encontrado']); exit; }

        $json = json_encode($doc, JSON_UNESCAPED_UNICODE);

        // Si ya había uno vinculado, archivamos
        $prev = $pdo->prepare("SELECT * FROM presupuestos_holded WHERE propuesta_id = ?");
        $prev->execute([$pid]);
        if ($old = $prev->fetch(PDO::FETCH_ASSOC)) {
            $pdo->prepare("INSERT INTO presupuestos_holded_history (propuesta_id, holded_id, holded_doc_number, holded_json, accion) VALUES (?, ?, ?, ?, 'reemplazado')")
                ->execute([$pid, $old['holded_id'], $old['holded_doc_number'], $old['holded_json']]);
            $pdo->prepare("UPDATE presupuestos_holded SET holded_id = ?, holded_doc_number = ?, holded_json = ?, synced_at = CURRENT_TIMESTAMP, estado = 'vinculado' WHERE propuesta_id = ?")
                ->execute([$id, $doc['docNumber'] ?? '', $json, $pid]);
        } else {
            $pdo->prepare("INSERT INTO presupuestos_holded (propuesta_id, holded_id, holded_doc_number, holded_json) VALUES (?, ?, ?, ?)")
                ->execute([$pid, $id, $doc['docNumber'] ?? '', $json]);
        }

        // Notificar Telegram
        if (function_exists('sendTelegramNotification')) {
            $cliStmt = $pdo->prepare("SELECT client_name FROM propuestas WHERE id = ?");
            $cliStmt->execute([$pid]);
            $cliente = $cliStmt->fetchColumn() ?: '—';
            sendTelegramNotification(
                "📋 <b>Presupuesto vinculado</b>"
                . "\n<b>" . htmlspecialchars($doc['docNumber'] ?? '—', ENT_QUOTES, 'UTF-8') . "</b>"
                . " · " . htmlspecialchars($cliente, ENT_QUOTES, 'UTF-8')
                . "\nTotal: <b>" . htmlspecialchars(holded_format_currency($doc['total'] ?? 0), ENT_QUOTES, 'UTF-8') . "</b>"
            );
        }
        echo json_encode(['success' => true, 'doc_number' => $doc['docNumber'] ?? '', 'total' => $doc['total'] ?? 0]);
        exit;
    }

    if ($_GET['action'] === 'holded_sync' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $pid = (int)($data['propuesta_id'] ?? 0);
        $row = $pdo->prepare("SELECT holded_id FROM presupuestos_holded WHERE propuesta_id = ?");
        $row->execute([$pid]);
        $hid = $row->fetchColumn();
        if (!$hid) { echo json_encode(['success' => false, 'message' => 'Sin presupuesto vinculado']); exit; }
        $doc = holded_get_estimate($hid);
        if (!$doc) { echo json_encode(['success' => false, 'message' => 'No accesible en Holded']); exit; }
        $pdo->prepare("UPDATE presupuestos_holded SET holded_json = ?, synced_at = CURRENT_TIMESTAMP WHERE propuesta_id = ?")
            ->execute([json_encode($doc, JSON_UNESCAPED_UNICODE), $pid]);
        echo json_encode(['success' => true, 'synced_at' => date('c'), 'total' => $doc['total'] ?? 0]);
        exit;
    }

    if ($_GET['action'] === 'holded_unlink' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $pid = (int)($data['propuesta_id'] ?? 0);
        $row = $pdo->prepare("SELECT * FROM presupuestos_holded WHERE propuesta_id = ?");
        $row->execute([$pid]);
        if ($old = $row->fetch(PDO::FETCH_ASSOC)) {
            $pdo->prepare("INSERT INTO presupuestos_holded_history (propuesta_id, holded_id, holded_doc_number, holded_json, accion) VALUES (?, ?, ?, ?, 'desvinculado')")
                ->execute([$pid, $old['holded_id'], $old['holded_doc_number'], $old['holded_json']]);
            $pdo->prepare("DELETE FROM presupuestos_holded WHERE propuesta_id = ?")->execute([$pid]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // Toggle Jordan-doc (activar/desactivar agente IA por propuesta)
    if ($_GET['action'] === 'toggle_jordan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($data['id']) && isset($data['enabled'])) {
            try {
                $stmt = $pdo->prepare("UPDATE propuestas SET enable_ai_assistant = :v WHERE id = :id");
                $stmt->execute([':v' => (int)$data['enabled'], ':id' => (int)$data['id']]);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        }
        exit;
    }

    // Resetear visitas
    if ($_GET['action'] === 'reset_views' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($data['id'])) {
            try {
                $stmt = $pdo->prepare("UPDATE propuestas SET views_count = 0 WHERE id = :id");
                $stmt->execute([':id' => (int)$data['id']]);
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

            // Auto-increment slug to avoid exact duplicates
            $original_slug = $slug;
            $counter = 1;
            while (true) {
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM propuestas WHERE slug = ? " . ($id ? "AND id != ?" : ""));
                if ($id) {
                    $checkStmt->execute([$slug, $id]);
                }
                else {
                    $checkStmt->execute([$slug]);
                }
                if ($checkStmt->fetchColumn() == 0) {
                    break;
                }
                $slug = $original_slug . '-' . $counter;
                $counter++;
            }

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
                $success_msg = "Propuesta actualizada. (Enlace: $slug)";
            }
            else {
                $stmt = $pdo->prepare("INSERT INTO propuestas (slug, client_name, pin, html_content, sent_date, version, equipo_ids) VALUES (:slug, :name, :pin, :html, :sent_date, :version, :equipo_ids)");
                $stmt->execute([':slug' => $slug, ':name' => $client_name, ':pin' => $pin, ':html' => $html_content, ':sent_date' => $sent_date ?: null, ':version' => $version, ':equipo_ids' => $equipo_ids]);
                $success_msg = "Propuesta creada. (Enlace: $slug)";
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

    // Presupuestos Holded vinculados — mapa propuesta_id → row
    $holdedRows = $pdo->query("SELECT propuesta_id, holded_id, holded_doc_number, holded_json, synced_at FROM presupuestos_holded")->fetchAll(PDO::FETCH_ASSOC);
    $holdedMap = [];
    foreach ($holdedRows as $r) {
        $j = json_decode($r['holded_json'], true) ?: [];
        $holdedMap[(int)$r['propuesta_id']] = [
            'id'        => $r['holded_id'],
            'docNumber' => $r['holded_doc_number'],
            'total'     => $j['total'] ?? 0,
            'synced_at' => $r['synced_at'],
        ];
    }

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
                                                    <?php echo $doc_approved ? 'checked' : '' ; ?>>
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
                                                    <?php echo $pres_approved ? 'checked' : '' ; ?>>
                                                <div
                                                    class="w-7 h-4 bg-bg-base border border-border-base rounded-full peer peer-checked:after:translate-x-[12px] after:content-[''] after:absolute after:top-[3px] after:left-[3px] after:bg-text-muted after:rounded-full after:h-2 after:w-2 after:transition-all peer-checked:after:bg-bg-base peer-checked:bg-tp-primary peer-checked:border-tp-primary">
                                                </div>
                                            </label>
                                        </div>

                                        <!-- Jordan IA Toggle (Haiku, scopeado al documento) -->
                                        <?php $jordan_on = !empty($p['enable_ai_assistant']); ?>
                                        <div class="flex items-center gap-2" title="Activa el agente Jordan (Haiku) en /p/<?php echo htmlspecialchars($p['slug']); ?>">
                                            <span
                                                class="text-[9px] font-bold uppercase tracking-wider <?php echo $jordan_on ? 'text-tp-primary' : 'text-text-muted'; ?>">IA</span>
                                            <label class="relative inline-flex items-center cursor-pointer">
                                                <input type="checkbox" class="sr-only peer"
                                                    onchange="toggleJordan(<?php echo $p['id']; ?>, this.checked, this)"
                                                    <?php echo $jordan_on ? 'checked' : '' ; ?>>
                                                <div
                                                    class="w-7 h-4 bg-bg-base border border-border-base rounded-full peer peer-checked:after:translate-x-[12px] after:content-[''] after:absolute after:top-[3px] after:left-[3px] after:bg-text-muted after:rounded-full after:h-2 after:w-2 after:transition-all peer-checked:after:bg-bg-base peer-checked:bg-tp-primary peer-checked:border-tp-primary">
                                                </div>
                                            </label>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap text-center">
                                    <?php $holdedLink = $holdedMap[(int)$p['id']] ?? null; ?>
                                    <div class="flex flex-col items-center gap-1.5">
                                        <?php if ($holdedLink): ?>
                                        <div class="flex items-center gap-2" title="Vinculado con Holded">
                                            <span class="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-tp-primary">
                                                <i data-lucide="link-2" class="w-3 h-3"></i>
                                                <?php echo htmlspecialchars($holdedLink['docNumber']); ?>
                                            </span>
                                            <button onclick="holdedSync(<?php echo $p['id']; ?>)" class="text-text-muted hover:text-tp-primary" title="Re-sincronizar con Holded">
                                                <i data-lucide="refresh-cw" class="w-3 h-3"></i>
                                            </button>
                                            <button onclick="holdedUnlink(<?php echo $p['id']; ?>)" class="text-red-500/60 hover:text-red-400" title="Desvincular">
                                                <i data-lucide="unlink" class="w-3 h-3"></i>
                                            </button>
                                        </div>
                                        <?php elseif (!empty($p['presupuesto_pdf'])): ?>
                                        <div class="flex items-center gap-2">
                                            <span class="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-tp-primary">
                                                <i data-lucide="file-text" class="w-3 h-3"></i> PDF
                                            </span>
                                            <button onclick="confirmDeletePdf(<?php echo $p['id']; ?>)" class="text-red-500/60 hover:text-red-400 transition-colors" title="Borrar PDF">
                                                <i data-lucide="trash-2" class="w-3 h-3"></i>
                                            </button>
                                        </div>
                                        <?php else: ?>
                                        <button onclick="openHoldedLink(<?php echo $p['id']; ?>, '<?php echo addslashes($p['client_name']); ?>')"
                                            class="text-[10px] font-bold uppercase tracking-wider text-tp-primary hover:text-white transition-colors flex items-center gap-1 mx-auto">
                                            <i data-lucide="link-2" class="w-3 h-3"></i> Holded
                                        </button>
                                        <button onclick="openUploadPDF(<?php echo $p['id']; ?>, '<?php echo addslashes($p['client_name']); ?>')"
                                            class="text-[9px] font-semibold uppercase tracking-wider text-text-muted hover:text-white transition-colors flex items-center gap-1 mx-auto">
                                            <i data-lucide="upload-cloud" class="w-3 h-3"></i> PDF (legacy)
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" class="sr-only peer"
                                            onchange="toggleStatus(<?php echo $p['id']; ?>, this.checked)" <?php echo
                                            $p['status']==1 ? 'checked' : '' ; ?>>
                                        <div
                                            class="w-10 h-5 bg-bg-base border border-border-base rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[4px] after:left-[4px] after:bg-text-muted after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:after:bg-bg-base peer-checked:bg-tp-primary peer-checked:border-tp-primary">
                                        </div>
                                    </label>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap font-heading font-bold text-white text-center">
                                    <div class="flex items-center gap-2 justify-center">
                                        <span>
                                            <?php echo $p['views_count']; ?>
                                        </span>
                                        <button
                                            onclick="resetViews(<?php echo $p['id']; ?>, '<?php echo addslashes($p['client_name']); ?>')"
                                            class="text-tp-primary/40 hover:text-tp-primary transition-colors cursor-pointer"
                                            title="Resetear visitas">
                                            <i data-lucide="refresh-cw" class="w-3 h-3"></i>
                                        </button>
                                    </div>
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
                        <div class="flex flex-col gap-3 pb-3">
                            <label class="flex items-center space-x-3 cursor-pointer">
                                <input type="checkbox" name="new_version" id="new_version" value="1"
                                    class="w-5 h-5 rounded border-border-base bg-bg-base text-tp-primary focus:ring-tp-primary focus:ring-offset-bg-surface">
                                <span class="text-sm font-semibold text-text-secondary">Guardar como nueva
                                    versión</span>
                            </label>
                            <div id="form-history-container" style="display: none;" class="flex items-center gap-2">
                                <i data-lucide="history" class="w-4 h-4 text-text-muted"></i>
                                <select id="form-history-select" onchange="restoreVersionFromForm(this.value)"
                                    class="bg-bg-base border border-border-base text-white text-xs rounded-lg px-2 py-1.5 outline-none focus:border-tp-primary transition-colors">
                                    <option value="">Restaurar versión anterior...</option>
                                </select>
                            </div>
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
                            <div class="flex items-center gap-2">
                                <button type="button" onclick="openFullscreenEditor()"
                                    class="text-xs bg-tp-primary/10 text-tp-primary border border-tp-primary/30 px-3 py-1.5 rounded hover:bg-tp-primary/20 transition-colors flex items-center gap-2">
                                    <i data-lucide="maximize" class="w-4 h-4"></i> Pantalla Completa
                                </button>
                                <button type="button" onclick="document.getElementById('file_upload').click()"
                                    class="text-xs bg-tp-primary/10 text-tp-primary border border-tp-primary/30 px-3 py-1.5 rounded hover:bg-tp-primary/20 transition-colors flex items-center gap-2">
                                    <i data-lucide="upload" class="w-4 h-4"></i> Subir HTML
                                </button>
                            </div>
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

    <!-- Modal Vincular Holded -->
    <div id="holded-overlay"
        class="fixed inset-0 bg-black/80 backdrop-blur-sm z-[90] hidden items-center justify-center">
        <div class="bg-bg-surface border border-border-subtle rounded-2xl shadow-2xl p-8 max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h3 class="text-lg font-heading font-bold text-white flex items-center gap-2">
                        <i data-lucide="link-2" class="w-5 h-5 text-tp-primary"></i>
                        Vincular presupuesto Holded
                    </h3>
                    <p class="text-xs text-text-muted mt-1" id="holded-cliente">—</p>
                </div>
                <button onclick="closeHolded()" class="text-text-muted hover:text-white transition-colors">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <input type="hidden" id="holded-propuesta-id" value="">

            <div class="mb-4">
                <label class="block text-sm font-semibold text-text-secondary mb-2">
                    Número o ID del presupuesto en Holded
                </label>
                <div class="flex gap-2">
                    <input type="text" id="holded-query" placeholder="E170380"
                        class="flex-1 bg-bg-base border border-border-base text-white rounded-xl px-4 py-3 outline-none focus:border-tp-primary font-mono">
                    <button onclick="holdedLookup()" class="bg-tp-primary/20 text-tp-primary font-bold px-5 rounded-xl hover:bg-tp-primary/30 transition-colors flex items-center gap-2">
                        <i data-lucide="search" class="w-4 h-4"></i> Buscar
                    </button>
                </div>
                <p class="text-[11px] text-text-muted mt-2">Puedes pegar el ID largo de la URL de Holded o el número de presupuesto. Mostramos sugerencias de los últimos.</p>
                <div id="holded-suggestions" class="mt-3 grid gap-1.5"></div>
            </div>

            <div id="holded-preview" class="mt-5 hidden">
                <div class="bg-bg-base border border-border-base rounded-xl p-5">
                    <div class="flex items-center justify-between mb-4 border-b border-border-base pb-3">
                        <div>
                            <div class="text-xs text-text-muted uppercase tracking-wider">Presupuesto</div>
                            <div class="text-xl font-heading font-bold text-tp-primary" id="hp-num">—</div>
                        </div>
                        <div class="text-right">
                            <div class="text-xs text-text-muted uppercase tracking-wider">Total</div>
                            <div class="text-xl font-heading font-bold text-white" id="hp-total">—</div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3 text-sm mb-4">
                        <div>
                            <div class="text-[10px] text-text-muted uppercase tracking-wider">Cliente</div>
                            <div class="text-white font-semibold" id="hp-cliente">—</div>
                        </div>
                        <div>
                            <div class="text-[10px] text-text-muted uppercase tracking-wider">Fecha</div>
                            <div class="text-white font-semibold" id="hp-fecha">—</div>
                        </div>
                        <div>
                            <div class="text-[10px] text-text-muted uppercase tracking-wider">Estado</div>
                            <div class="text-white font-semibold" id="hp-estado">—</div>
                        </div>
                        <div>
                            <div class="text-[10px] text-text-muted uppercase tracking-wider">Líneas</div>
                            <div class="text-white font-semibold" id="hp-lineas">—</div>
                        </div>
                    </div>
                    <details class="text-xs">
                        <summary class="cursor-pointer text-text-muted hover:text-white">Ver líneas de detalle</summary>
                        <ul id="hp-items" class="mt-2 space-y-1 text-text-secondary"></ul>
                    </details>
                </div>

                <div class="flex gap-3 mt-5">
                    <button onclick="holdedConfirm()" class="flex-1 bg-tp-primary text-bg-base font-bold hover:bg-tp-primary-hover transition-colors rounded-xl py-3 flex items-center justify-center gap-2">
                        <i data-lucide="link-2" class="w-4 h-4"></i> Vincular a esta propuesta
                    </button>
                    <button onclick="closeHolded()" class="bg-bg-subtle text-white px-6 rounded-xl border border-border-base hover:bg-border-strong transition-colors">Cancelar</button>
                </div>
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

    <!-- Fullscreen Editor Overlay -->
    <div id="fs-editor-overlay" class="fixed inset-0 bg-bg-base z-[100] hidden flex-col">
        <!-- Topbar -->
        <div class="h-16 border-b border-border-base flex items-center justify-between px-6 bg-bg-surface shrink-0">
            <div class="flex items-center gap-4">
                <button type="button" onclick="closeFullscreenEditor()"
                    class="text-text-muted hover:text-white transition-colors flex items-center gap-2 text-sm font-semibold">
                    <i data-lucide="arrow-left" class="w-4 h-4"></i> <span class="hidden sm:inline">Volver</span>
                </button>
            </div>

            <div class="flex items-center gap-4">
                <!-- Select history -->
                <div class="flex items-center gap-2" id="fs-history-container" style="display: none;">
                    <label class="text-xs font-semibold text-text-muted hidden sm:block"><i data-lucide="history"
                            class="w-3.5 h-3.5 inline mr-1"></i> Recuperar:</label>
                    <select id="fs-history-select" onchange="loadHistoryVersion(this.value)"
                        class="bg-bg-base border border-border-base text-white text-xs rounded-lg px-2 py-1.5 outline-none focus:border-tp-primary transition-colors max-w-[150px]">
                        <option value="">Actual</option>
                    </select>
                </div>

                <button type="button" onclick="applyFromFullscreen()"
                    class="bg-tp-primary text-bg-base font-bold hover:bg-tp-primary-hover transition-colors rounded-xl px-4 py-2 text-sm flex items-center gap-2">
                    <i data-lucide="check" class="w-4 h-4"></i> <span class="hidden sm:inline">Aplicar al
                        Formulario</span><span class="sm:hidden">Aplicar</span>
                </button>
            </div>
        </div>

        <!-- Split Screen Content -->
        <div class="flex-1 flex flex-col md:flex-row overflow-hidden relative">
            <!-- Left: Editor ACE -->
            <div class="w-full h-1/2 md:h-full md:w-1/2 flex flex-col border-b md:border-b-0 md:border-r border-border-base"
                style="background-color: #1e1e1e;">
                <div id="ace-editor" class="w-full h-full"></div>
            </div>

            <!-- Right: Live Preview -->
            <div class="w-full h-1/2 md:h-full md:w-1/2 flex flex-col relative bg-white">
                <iframe id="fs-preview" class="w-full h-full border-0 bg-white"></iframe>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/preline/dist/preline.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/lucide/dist/umd/lucide.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.7/ace.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.7/ext-language_tools.js"></script>
    <script>
        lucide.createIcons();

        // --- Fullscreen Editor Logic ---
        let editor = null;
        let previewTimer = null;

        function initAceEditor() {
            if (editor) return;

            ace.require("ace/ext/language_tools");
            editor = ace.edit("ace-editor");
            editor.setTheme("ace/theme/tomorrow_night_eighties");
            editor.session.setMode("ace/mode/html");
            editor.setOptions({
                fontSize: "13px",
                showPrintMargin: false,
                highlightActiveLine: true,
                enableBasicAutocompletion: true,
                enableLiveAutocompletion: true,
                wrap: true,
                useWorker: false
            });

            editor.session.on('change', function () {
                clearTimeout(previewTimer);
                previewTimer = setTimeout(updatePreview, 500);
            });
        }

        function updatePreview() {
            const html = editor.getValue();
            const iframe = document.getElementById('fs-preview');
            const doc = iframe.contentDocument || iframe.contentWindow.document;
            doc.open();
            doc.write(html);
            doc.close();
        }

        async function fetchHistory(propuesta_id) {
            const select = document.getElementById('fs-history-select');
            const container = document.getElementById('fs-history-container');
            select.innerHTML = '<option value="">Actual</option>';

            if (!propuesta_id) {
                container.style.display = 'none';
                return;
            }

            try {
                const res = await fetch(`admin.php?action=get_history&id=${propuesta_id}`);
                const data = await res.json();
                if (data.success && data.history.length > 0) {
                    container.style.display = 'flex';
                    data.history.forEach(h => {
                        const date = new Date(h.created_at).toLocaleString('es-ES', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' });
                        select.innerHTML += `<option value="${h.id}">${h.version} (${date})</option>`;
                    });
                } else {
                    container.style.display = 'none';
                }
            } catch (e) {
                console.error("Error cargando historial:", e);
                container.style.display = 'none';
            }
        }

        async function loadHistoryVersion(history_id) {
            if (!history_id) return;
            if (!confirm('¿Cargar versión anterior en el editor?')) {
                document.getElementById('fs-history-select').value = "";
                return;
            }

            try {
                const res = await fetch(`admin.php?action=get_history_html&history_id=${history_id}`);
                const data = await res.json();
                if (data.success) {
                    editor.setValue(data.html, -1);
                    updatePreview();
                } else {
                    alert('No se pudo cargar la versión.');
                }
            } catch (e) {
                console.error(e);
            }
            document.getElementById('fs-history-select').value = "";
        }

        function openFullscreenEditor() {
            initAceEditor();

            const proposalId = document.getElementById('form-id').value;
            const currentHTML = document.getElementById('html_content').value;
            editor.setValue(currentHTML, -1);
            updatePreview();

            fetchHistory(proposalId);

            document.getElementById('fs-editor-overlay').classList.remove('hidden');
            document.getElementById('fs-editor-overlay').classList.add('flex');

            if (window.lucide) lucide.createIcons();

            setTimeout(() => editor.resize(), 100);
        }

        function closeFullscreenEditor() {
            document.getElementById('fs-editor-overlay').classList.remove('flex');
            document.getElementById('fs-editor-overlay').classList.add('hidden');
        }

        function applyFromFullscreen() {
            document.getElementById('html_content').value = editor.getValue();
            closeFullscreenEditor();
        }
        // --- End Fullscreen Editor Logic ---

        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => alert('Enlace copiado'));
        }

        async function resetViews(id, name) {
            if (!confirm(`¿Estás seguro de que quieres resetear a 0 las visitas de ${name}?`)) return;
            try {
                const res = await fetch('admin.php?action=reset_views', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await res.json();
                if (data.success) window.location.reload();
                else alert(data.message);
            } catch (err) {
                console.error(err);
                alert("Error de conexión al resetear vistas.");
            }
        }

        async function toggleStatus(id, active) {
            await fetch('admin.php?action=toggle_status', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, status: active ? 1 : 0 })
            });
        }

        async function toggleJordan(id, enabled, el) {
            try {
                const res = await fetch('admin.php?action=toggle_jordan', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id, enabled: enabled ? 1 : 0}),
                });
                const d = await res.json();
                if (!d.success) { el.checked = !enabled; alert(d.message || 'Error'); return; }
                // Actualizar label visual
                const label = el.closest('.flex').querySelector('span');
                if (label) {
                    label.classList.toggle('text-tp-primary', enabled);
                    label.classList.toggle('text-text-muted', !enabled);
                }
            } catch (e) { el.checked = !enabled; alert('Error de red'); }
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
            if (document.getElementById('new_version')) document.getElementById('new_version').checked = false;

            document.querySelectorAll('input[name="equipo_ids[]"]').forEach(cb => cb.checked = false);
            try {
                if (p.equipo_ids) {
                    const parsed = typeof p.equipo_ids === 'string' ? JSON.parse(p.equipo_ids) : p.equipo_ids;
                    if (Array.isArray(parsed)) {
                        parsed.forEach(id => {
                            const cb = document.getElementById('equipo_' + id);
                            if (cb) cb.checked = true;
                        });
                    }
                }
            } catch (e) {
                console.error('Error parsing equipo_ids:', e);
            }

            fetchFormHistory(p.id);
            openDrawer('hs-overlay-create');
        }

        async function fetchFormHistory(propuestaId) {
            const select = document.getElementById('form-history-select');
            const container = document.getElementById('form-history-container');
            select.innerHTML = '<option value="">Restaurar versión anterior...</option>';

            if (!propuestaId) {
                container.style.display = 'none';
                return;
            }

            try {
                const res = await fetch(`admin.php?action=get_history&id=${propuestaId}`);
                const data = await res.json();
                if (data.success && data.history.length > 0) {
                    container.style.display = 'flex';
                    data.history.forEach(h => {
                        const date = new Date(h.created_at).toLocaleString('es-ES', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' });
                        select.innerHTML += `<option value="${h.id}">${h.version} (${date})</option>`;
                    });
                } else {
                    container.style.display = 'none';
                }
            } catch (e) {
                container.style.display = 'none';
            }
        }

        async function restoreVersionFromForm(historyId) {
            if (!historyId) return;
            if (!confirm('¿Restaurar esta versión? El contenido actual del editor será reemplazado.')) {
                document.getElementById('form-history-select').value = '';
                return;
            }

            try {
                const res = await fetch(`admin.php?action=get_history_html&history_id=${historyId}`);
                const data = await res.json();
                if (data.success) {
                    document.getElementById('html_content').value = data.html;
                } else {
                    alert('No se pudo cargar la versión.');
                }
            } catch (e) {
                alert('Error al restaurar versión.');
            }
            document.getElementById('form-history-select').value = '';
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
            if (document.getElementById('new_version')) document.getElementById('new_version').checked = false;
            document.querySelectorAll('input[name="equipo_ids[]"]').forEach(cb => cb.checked = false);
            document.getElementById('form-history-container').style.display = 'none';
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

        // ===== Holded — vincular / buscar / sync / unlink =====
        let _holdedPreviewDoc = null;

        function openHoldedLink(id, name) {
            document.getElementById('holded-propuesta-id').value = id;
            document.getElementById('holded-cliente').innerText = name;
            document.getElementById('holded-query').value = '';
            document.getElementById('holded-suggestions').innerHTML = '';
            document.getElementById('holded-preview').classList.add('hidden');
            document.getElementById('holded-overlay').style.display = 'flex';
            _holdedPreviewDoc = null;
            setTimeout(() => { document.getElementById('holded-query').focus(); holdedSuggest(''); }, 100);
        }
        function closeHolded() { document.getElementById('holded-overlay').style.display = 'none'; }

        async function holdedSuggest(q) {
            try {
                const res = await fetch('admin.php?action=holded_search&q=' + encodeURIComponent(q));
                const data = await res.json();
                if (!data.success) return;
                const el = document.getElementById('holded-suggestions');
                el.innerHTML = data.items.map(i => `
                    <button type="button" onclick="holdedPickSuggestion('${i.id}', '${i.docNumber}')"
                        class="text-left bg-bg-base border border-border-base rounded-lg px-3 py-2 hover:border-tp-primary transition-colors flex justify-between items-center">
                        <div>
                            <span class="font-mono text-tp-primary">${i.docNumber}</span>
                            <span class="text-text-muted ml-2">· ${i.contactName || '—'}</span>
                        </div>
                        <div class="text-xs text-text-secondary">${i.total_fmt} · ${i.date_fmt}</div>
                    </button>`).join('');
            } catch (e) { /* silencio */ }
        }

        function holdedPickSuggestion(id, num) {
            document.getElementById('holded-query').value = num;
            holdedLookup(id);
        }

        async function holdedLookup(forcedId) {
            const q = (document.getElementById('holded-query').value || '').trim();
            if (!q && !forcedId) return;
            const params = forcedId ? 'holded_id=' + encodeURIComponent(forcedId) : 'doc_number=' + encodeURIComponent(q);
            const btnArea = document.getElementById('holded-preview');
            btnArea.classList.add('hidden');
            try {
                const res = await fetch('admin.php?action=holded_preview&' + params);
                const data = await res.json();
                if (!data.success) { alert(data.message || 'No encontrado'); return; }
                _holdedPreviewDoc = data.data;
                document.getElementById('hp-num').innerText = data.data.docNumber || '—';
                document.getElementById('hp-total').innerText = formatEur(data.data.total || 0);
                document.getElementById('hp-cliente').innerText = data.data.contactName || '—';
                document.getElementById('hp-fecha').innerText = fmtDate(data.data.date);
                document.getElementById('hp-estado').innerText = statusLabel(data.data.status);
                const items = data.data.products || [];
                document.getElementById('hp-lineas').innerText = items.length;
                document.getElementById('hp-items').innerHTML = items.map(it =>
                    `<li class="border-l border-border-base pl-2">
                        <strong class="text-white">${escapeHtml(it.name || '')}</strong>
                        <span class="text-text-muted">— ${it.units || 1} × ${formatEur(it.price || 0)}</span>
                    </li>`).join('');
                btnArea.classList.remove('hidden');
                if (window.lucide) lucide.createIcons();
            } catch (e) { alert('Error de red'); }
        }

        async function holdedConfirm() {
            if (!_holdedPreviewDoc) return;
            const pid = +document.getElementById('holded-propuesta-id').value;
            const res = await fetch('admin.php?action=holded_link', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({propuesta_id: pid, holded_id: _holdedPreviewDoc.id}),
            });
            const data = await res.json();
            if (!data.success) { alert(data.message || 'Error'); return; }
            window.location.reload();
        }

        async function holdedSync(pid) {
            const res = await fetch('admin.php?action=holded_sync', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({propuesta_id: pid}),
            });
            const d = await res.json();
            if (!d.success) { alert(d.message || 'Error'); return; }
            window.location.reload();
        }

        async function holdedUnlink(pid) {
            if (!confirm('¿Desvincular este presupuesto de Holded?')) return;
            const res = await fetch('admin.php?action=holded_unlink', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({propuesta_id: pid}),
            });
            const d = await res.json();
            if (!d.success) { alert(d.message || 'Error'); return; }
            window.location.reload();
        }

        // helpers
        function formatEur(n) { return (new Intl.NumberFormat('es-ES', {minimumFractionDigits:2, maximumFractionDigits:2}).format(+n||0)) + ' €'; }
        function fmtDate(ts) { if (!ts) return '—'; const d = new Date((+ts) * 1000); return isNaN(d) ? ts : d.toLocaleDateString('es-ES'); }
        function statusLabel(s) { return ({0:'Pendiente',1:'Aprobado',2:'Rechazado',3:'Facturado',4:'Vencido'})[+s] || ('Estado '+s); }
        function escapeHtml(s) { return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

        // Autocomplete en vivo
        document.addEventListener('input', (e) => {
            if (e.target && e.target.id === 'holded-query') {
                clearTimeout(window._hQueryT);
                window._hQueryT = setTimeout(() => holdedSuggest(e.target.value), 300);
            }
        });

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