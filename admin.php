<?php
session_start();
// Sesión admin unificada: is_admin y admin_logged son equivalentes
// (evita que Analytics/Comentarios/etc. vuelvan a pedir la contraseña al saltar entre vistas).
if (!empty($_SESSION['is_admin']))     { $_SESSION['admin_logged'] = true; }
if (!empty($_SESSION['admin_logged'])) { $_SESSION['is_admin']     = true; }
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
        $confirmOverwrite = !empty($data['confirm_overwrite']);
        if (!$pid || !$id) { echo json_encode(['success' => false, 'message' => 'Faltan datos']); exit; }

        // Si la propuesta ya tiene firma del PDF legacy, avisar: la firma se hizo
        // sobre el PDF, no sobre este Holded. Vincular sin confirmar la invalidaría.
        if (!$confirmOverwrite) {
            $firmaCheck = $pdo->prepare(
                "SELECT a.firmante_nombre, a.firmante_apellidos, a.aprobado_at, a.version_firmada,
                        (p.presupuesto_pdf IS NOT NULL AND p.presupuesto_pdf != '') AS has_pdf
                   FROM aprobaciones a
                   JOIN propuestas p ON p.id = a.propuesta_id
                  WHERE a.propuesta_id = ? AND a.tipo = 'presupuesto'
                  ORDER BY a.aprobado_at DESC LIMIT 1"
            );
            $firmaCheck->execute([$pid]);
            $firma = $firmaCheck->fetch(PDO::FETCH_ASSOC);
            if ($firma && (int)$firma['has_pdf'] === 1) {
                $nombre = trim(($firma['firmante_nombre'] ?? '') . ' ' . ($firma['firmante_apellidos'] ?? '')) ?: '—';
                echo json_encode([
                    'success' => false,
                    'needs_confirmation' => true,
                    'message' => "Esta propuesta ya tiene una firma del PDF actual (" . $nombre . " · " . $firma['aprobado_at'] . "). Vincular Holded mostrará este nuevo presupuesto como 'aprobado' aunque el cliente nunca lo haya firmado. ¿Seguro que quieres continuar?",
                    'firma' => ['nombre' => $nombre, 'aprobado_at' => $firma['aprobado_at'], 'version' => $firma['version_firmada']],
                ]);
                exit;
            }
        }

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

    // Stats de comentarios por propuesta (bandeja)
    // open_no_reply = raíz abierto sin respuesta staff publicada
    // open_answered = raíz abierto con respuesta staff publicada (espera cierre del cliente)
    // drafts = borradores staff sin publicar
    $commentStats = [];
    $statRows = $pdo->query("
        SELECT
            propuesta_id,
            SUM(CASE WHEN parent_id IS NULL AND resuelto = 0 THEN 1 ELSE 0 END) AS open_roots,
            SUM(CASE WHEN is_staff = 1 AND is_draft = 1 THEN 1 ELSE 0 END) AS drafts,
            SUM(CASE WHEN is_staff = 1 AND is_draft = 0 AND notificado_at IS NULL THEN 1 ELSE 0 END) AS published_unnotified
        FROM comentarios_seccion
        GROUP BY propuesta_id
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($statRows as $s) {
        $commentStats[(int)$s['propuesta_id']] = [
            'open' => (int)$s['open_roots'],
            'drafts' => (int)$s['drafts'],
            'pending_notify' => (int)$s['published_unnotified'],
        ];
    }
    // Global: total hilos abiertos en todo el sistema
    $globalOpen = (int)$pdo->query("SELECT COUNT(*) FROM comentarios_seccion WHERE parent_id IS NULL AND resuelto = 0")->fetchColumn();
    $globalDrafts = (int)$pdo->query("SELECT COUNT(*) FROM comentarios_seccion WHERE is_staff = 1 AND is_draft = 1")->fetchColumn();

    // --- Portal proveedores: stats por propuesta + shelf detail ---
    // Estructura: $providerStats[$propId] = ['total'=>N, 'presupuestos'=>N, 'shelf'=>[ {id,nombre,empresa,inicial,state,importe,days_since,version}, ... ]]
    $providerStats = [];
    try {
        // Totales por propuesta (igual que antes)
        $psRows = $pdo->query("
            SELECT p.propuesta_id,
                   COUNT(*) AS total_proveedores,
                   (SELECT COUNT(*) FROM proveedor_presupuestos pp JOIN propuesta_proveedores p2 ON p2.id = pp.proveedor_id WHERE p2.propuesta_id = p.propuesta_id) AS total_presupuestos
            FROM propuesta_proveedores p
            WHERE p.activo = 1
            GROUP BY p.propuesta_id
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($psRows as $ps) {
            $providerStats[(int)$ps['propuesta_id']] = [
                'total' => (int)$ps['total_proveedores'],
                'presupuestos' => (int)$ps['total_presupuestos'],
                'shelf' => [],
            ];
        }

        // Shelf detail: 1 fila por proveedor activo · último presupuesto + estado
        // (LEFT JOIN para que aparezca aunque no haya subido nada)
        // Si decision_state no existe (migración no corrida) lo defensiveamos
        $hasDecision = false;
        try {
            $col = $pdo->query("PRAGMA table_info(proveedor_presupuestos)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($col as $c) if ($c['name'] === 'decision_state') { $hasDecision = true; break; }
        } catch (\Throwable $_) {}

        if ($hasDecision) {
            $shelfSql = "
                SELECT p.id AS prov_id, p.propuesta_id, p.nombre, p.empresa,
                       pp.id AS budget_id, pp.version_num, pp.importe_total,
                       pp.decision_state, pp.decision_at, pp.uploaded_at
                FROM propuesta_proveedores p
                LEFT JOIN proveedor_presupuestos pp ON pp.id = (
                    SELECT id FROM proveedor_presupuestos WHERE proveedor_id = p.id ORDER BY version_num DESC LIMIT 1
                )
                WHERE p.activo = 1
                ORDER BY p.propuesta_id, p.invited_at DESC
            ";
        } else {
            $shelfSql = "
                SELECT p.id AS prov_id, p.propuesta_id, p.nombre, p.empresa,
                       pp.id AS budget_id, pp.version_num, pp.importe_total,
                       NULL AS decision_state, NULL AS decision_at, pp.uploaded_at
                FROM propuesta_proveedores p
                LEFT JOIN proveedor_presupuestos pp ON pp.id = (
                    SELECT id FROM proveedor_presupuestos WHERE proveedor_id = p.id ORDER BY version_num DESC LIMIT 1
                )
                WHERE p.activo = 1
                ORDER BY p.propuesta_id, p.invited_at DESC
            ";
        }
        $shelfRows = $pdo->query($shelfSql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($shelfRows as $r) {
            $propId = (int)$r['propuesta_id'];
            if (!isset($providerStats[$propId])) continue;
            $state = $r['decision_state'] ?: ($r['budget_id'] ? 'recibido' : 'sin_budget');
            $refDate = $r['decision_at'] ?: $r['uploaded_at'];
            $daysSince = null;
            if ($refDate) {
                $daysSince = (int)floor((time() - strtotime($refDate)) / 86400);
            }
            $providerStats[$propId]['shelf'][] = [
                'id'         => (int)$r['prov_id'],
                'nombre'     => $r['nombre'] ?? '',
                'empresa'    => $r['empresa'] ?? '',
                'inicial'    => mb_strtoupper(mb_substr($r['nombre'] ?? '?', 0, 1)),
                'state'      => $state,
                'version'    => $r['version_num'] ? (int)$r['version_num'] : null,
                'importe'    => $r['importe_total'] !== null ? (float)$r['importe_total'] : null,
                'days_since' => $daysSince,
                'has_budget' => !empty($r['budget_id']),
            ];
        }
    } catch (Exception $e) { /* tabla no existe aún */ }

    // --- Sprint 2.2 · Actividad / señales calientes por propuesta ---
    // Tabla propuesta_eventos puede no existir aún en entornos viejos → try/catch defensivo
    $activityStats = [];
    $globalActiveNow = 0;
    $globalOpened24h = 0;
    try {
        // Excluimos eventos internos (equipo Tres Puntos) del dashboard.
        // Los eventos crudos siguen guardados — admin_analytics.php tiene un toggle para verlos.
        $actRows = $pdo->query("
            SELECT
                propuesta_id,
                MAX(created_at) AS last_event_at,
                COUNT(DISTINCT sesion_id) AS sesiones_totales,
                COUNT(DISTINCT CASE WHEN created_at >= datetime('now', '-1 day') THEN sesion_id END) AS sesiones_24h,
                COUNT(DISTINCT CASE WHEN created_at >= datetime('now', '-2 minutes') THEN sesion_id END) AS activo_ahora,
                SUM(CASE WHEN tipo = 'presupuesto_open' THEN 1 ELSE 0 END) AS presupuesto_opens,
                SUM(CASE WHEN tipo = 'firma_abandoned' THEN 1 ELSE 0 END) AS firmas_abandoned,
                SUM(CASE WHEN tipo = 'firma_approved' THEN 1 ELSE 0 END) AS firmas_ok
            FROM propuesta_eventos
            WHERE is_internal IS NULL OR is_internal = 0
            GROUP BY propuesta_id
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($actRows as $a) {
            $activityStats[(int)$a['propuesta_id']] = [
                'last_event_at' => $a['last_event_at'],
                'sesiones' => (int)$a['sesiones_totales'],
                'sesiones_24h' => (int)$a['sesiones_24h'],
                'activo_ahora' => (int)$a['activo_ahora'] > 0,
                'vio_presupuesto' => (int)$a['presupuesto_opens'] > 0,
                'intento_firma' => (int)$a['firmas_abandoned'] > 0 && (int)$a['firmas_ok'] === 0,
            ];
            if ($activityStats[(int)$a['propuesta_id']]['activo_ahora']) $globalActiveNow++;
            if ($activityStats[(int)$a['propuesta_id']]['sesiones_24h'] > 0) $globalOpened24h++;
        }
    } catch (Exception $e) {
        // Tabla aún no existe (entorno sin migrar) → stats vacías
    }
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

        /* Iter 2 · Provider shelf en fila de propuesta */
        .tp-shelf-avatar {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px; height: 26px;
            border-radius: 50%;
            background: rgba(192, 132, 252, 0.12);
            color: #c084fc;
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: .02em;
            border: 1px solid rgba(192, 132, 252, 0.35);
            text-decoration: none;
            cursor: pointer;
            transition: transform .12s ease, border-color .12s ease, background .12s ease;
            flex-shrink: 0;
            font-family: 'Inter', system-ui, sans-serif;
        }
        .tp-shelf-avatar:hover {
            transform: scale(1.08);
            background: rgba(192, 132, 252, 0.2);
            border-color: rgba(192, 132, 252, 0.65);
        }
        .tp-shelf-avatar:focus-visible {
            outline: 2px solid #5dffbf;
            outline-offset: 2px;
        }

        .tp-shelf-dot {
            position: absolute;
            top: -2px; right: -2px;
            width: 9px; height: 9px;
            border-radius: 50%;
            border: 2px solid #0e0e0e;
            display: grid;
            place-items: center;
        }
        .tp-shelf-dot--yellow {
            background: #ffd84d;
            box-shadow: 0 0 0 0 rgba(255, 216, 77, .65);
            animation: tpShelfPulseYellow 1.8s ease-in-out infinite;
        }
        @keyframes tpShelfPulseYellow {
            0%, 100% { box-shadow: 0 0 0 0 rgba(255, 216, 77, .55); }
            70% { box-shadow: 0 0 0 5px rgba(255, 216, 77, 0); }
        }
        .tp-shelf-dot--amber { background: #fac850; }
        .tp-shelf-dot--mint {
            background: #5dffbf;
            box-shadow: 0 0 4px rgba(93, 255, 191, .4);
        }
        .tp-shelf-dot--red { background: #ff6b6b; }
        .tp-shelf-dot--purple { background: #c084fc; }
        .tp-shelf-dot--dashed {
            background: transparent;
            border: 2px dashed #8a8a8a;
            width: 10px; height: 10px;
        }

        .tp-shelf-invite {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px; height: 26px;
            border-radius: 50%;
            background: transparent;
            border: 1px dashed #2a2a2a;
            color: #8a8a8a;
            text-decoration: none;
            transition: all .12s ease;
            flex-shrink: 0;
        }
        .tp-shelf-invite:hover {
            color: #c084fc;
            border-color: rgba(192, 132, 252, .55);
            background: rgba(192, 132, 252, .06);
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/preline/dist/preline.min.js" defer></script>
</head>

<body
    class="bg-bg-base text-text-primary antialiased min-h-screen flex flex-col selection:bg-tp-primary selection:text-bg-base">
    <?php include __DIR__ . '/master/admin-faceid.php'; ?>

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
    <script src="https://unpkg.com/lucide@latest"></script>
    <?php
    // Sidebar shared — dashboard activo
    $adminSidebarActive = 'dashboard';
    $adminSidebarPropuestaId = 0;
    $adminSidebarPropuestaSlug = null;
    $adminSidebarPropuestas = $proposals ?? [];
    ?>
    <div class="admin-layout">
    <?php include __DIR__ . '/master/admin-sidebar.php'; ?>

    <main class="admin-main">
        <?php
        // H3: breadcrumb (solo Dashboard en este nivel raíz)
        $adminBreadcrumbItems = [['label' => 'Dashboard', 'href' => null]];
        include __DIR__ . '/master/admin-breadcrumb.php';
        ?>
        <div class="admin-main-header">
            <h1 class="admin-main-title">
                <i data-lucide="layout-dashboard"></i>
                Dashboard
                <small>· Admin Panel v2.0</small>
            </h1>
            <div class="admin-main-actions">
                <a class="text-sm font-medium text-text-secondary hover:text-white transition-colors"
                    href="?logout=1">Cerrar Sesión</a>
            </div>
        </div>
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

        <!-- 4 KPIs uniformes (sin destacado) · reverted Fix 4 feedback 2026-04-24 -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-10">
            <!-- KPI 1 · Propuestas Activas -->
            <div class="bg-bg-surface border border-border-subtle/70 rounded-2xl p-5 shadow-[0_1px_2px_rgba(0,0,0,0.15)]">
                <p class="text-xs uppercase tracking-wider text-text-muted font-semibold mb-3">Propuestas Activas</p>
                <div class="flex flex-col">
                    <h3 class="text-4xl font-heading font-bold text-white leading-none"><?php echo $total_proposals; ?></h3>
                    <span class="text-xs text-tp-primary font-medium mt-2">Online</span>
                </div>
            </div>

            <!-- KPI 2 · Visualizaciones -->
            <div class="bg-bg-surface border border-border-subtle/70 rounded-2xl p-5 shadow-[0_1px_2px_rgba(0,0,0,0.15)]">
                <p class="text-xs uppercase tracking-wider text-text-muted font-semibold mb-3">Visualizaciones</p>
                <div class="flex flex-col">
                    <h3 class="text-4xl font-heading font-bold text-white leading-none"><?php echo number_format($total_views); ?></h3>
                    <span class="inline-flex items-center gap-1.5 text-xs text-text-muted mt-2">
                        <i data-lucide="eye" class="w-3.5 h-3.5"></i> Lifetime
                    </span>
                </div>
            </div>

            <!-- KPI 3 · Comentarios pendientes -->
            <a href="admin_feedback.php"
               class="bg-bg-surface border <?= $globalOpen > 0 ? 'border-tp-primary/40 shadow-[0_0_20px_rgba(93,255,191,0.08)]' : 'border-border-subtle/70 shadow-[0_1px_2px_rgba(0,0,0,0.15)]' ?> rounded-2xl p-5 block transition-all hover:-translate-y-0.5 hover:border-tp-primary/60">
                <p class="text-xs uppercase tracking-wider <?= $globalOpen > 0 ? 'text-tp-primary' : 'text-text-muted' ?> font-semibold mb-3">Comentarios pendientes</p>
                <div class="flex flex-col">
                    <h3 class="text-4xl font-heading font-bold <?= $globalOpen > 0 ? 'text-tp-primary' : 'text-white' ?> leading-none">
                        <?php echo $globalOpen; ?>
                    </h3>
                    <?php if ($globalDrafts > 0): ?>
                        <span class="text-xs text-text-muted mt-2"><?= $globalDrafts ?> borrador<?= $globalDrafts === 1 ? '' : 'es' ?> sin publicar</span>
                    <?php elseif ($globalOpen === 0): ?>
                        <span class="inline-flex items-center gap-1.5 text-xs text-text-muted mt-2">Todo al día <i data-lucide="check" class="w-3 h-3 text-tp-primary"></i></span>
                    <?php else: ?>
                        <span class="text-xs text-tp-primary font-medium mt-2">Ir a la bandeja →</span>
                    <?php endif; ?>
                </div>
            </a>

            <!-- KPI 4 · Actividad en vivo -->
            <a href="admin_analytics.php"
               class="bg-bg-surface border <?= $globalActiveNow > 0 ? 'border-red-500/40 shadow-[0_0_20px_rgba(239,68,68,0.08)]' : 'border-border-subtle/70 shadow-[0_1px_2px_rgba(0,0,0,0.15)]' ?> rounded-2xl p-5 block transition-all hover:-translate-y-0.5 hover:border-red-500/40">
                <p class="text-xs uppercase tracking-wider <?= $globalActiveNow > 0 ? 'text-red-400' : 'text-text-muted' ?> font-semibold mb-3">Actividad en vivo</p>
                <div class="flex flex-col">
                    <div class="flex items-center gap-2.5 leading-none">
                        <h3 class="text-4xl font-heading font-bold <?= $globalActiveNow > 0 ? 'text-red-400' : 'text-white' ?>"><?= $globalActiveNow ?></h3>
                        <?php if ($globalActiveNow > 0): ?>
                            <span class="relative inline-flex w-2.5 h-2.5 mb-1">
                                <span class="absolute inline-flex w-full h-full rounded-full bg-red-400 opacity-60 animate-ping"></span>
                                <span class="relative inline-flex w-2.5 h-2.5 rounded-full bg-red-400"></span>
                            </span>
                        <?php endif; ?>
                    </div>
                    <span class="text-xs text-text-muted mt-2">
                        <?= $globalOpened24h ?> propuesta<?= $globalOpened24h === 1 ? '' : 's' ?> abierta<?= $globalOpened24h === 1 ? '' : 's' ?> en 24h
                    </span>
                </div>
            </a>
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
                    <table class="min-w-full divide-y divide-border-subtle tp-table-cards">
                        <thead class="bg-bg-subtle/50">
                            <tr>
                                <th class="px-6 py-4 text-start text-xs font-semibold text-text-muted uppercase tracking-wider">Cliente</th>
                                <th class="px-6 py-4 text-start text-xs font-semibold text-text-muted uppercase tracking-wider">Documento</th>
                                <th class="px-6 py-4 text-center text-xs font-semibold text-text-muted uppercase tracking-wider">Estado</th>
                                <th class="px-6 py-4 text-center text-xs font-semibold text-text-muted uppercase tracking-wider">Tráfico</th>
                                <th class="px-6 py-4 text-end text-xs font-semibold text-text-muted uppercase tracking-wider">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-subtle">
                            <?php if (empty($proposals)): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center text-text-muted italic">No hay propuestas
                                    registradas.</td>
                            </tr>
                            <?php
    else:
        foreach ($proposals as $p):
            $cs = $commentStats[(int)$p['id']] ?? ['open' => 0, 'drafts' => 0, 'pending_notify' => 0];
            $as = $activityStats[(int)$p['id']] ?? null;
            // Señal "fría" — sin abrir hace >5 días (y alguna vez se abrió)
            $coldDays = null;
            if ($as && $as['last_event_at']) {
                $daysSince = (time() - strtotime($as['last_event_at'])) / 86400;
                if ($daysSince > 5) $coldDays = (int)floor($daysSince);
            }
    ?>
                            <?php
                            // Fix 1 + 3 + 10: 3 tiers de chips + jerarquía clara + dot pulsante en lugar de chip "En vivo"
                            // Tier 1 — URGENTE (rojo): intento firma, (dot directamente para en vivo)
                            // Tier 2 — INTERESANTE (mint): vio precio, 🔥 hoy, 💬 comentarios abiertos
                            // Tier 3 — INFO (gris): ❄️ frío, ✏️ borradores, ✉ sin avisar
                            $tier1 = 'inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-red-500/15 text-red-400 border border-red-500/40';
                            $tier2 = 'inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-tp-primary/10 text-tp-primary border border-tp-primary/25';
                            $tier3 = 'inline-flex items-center gap-1 text-[10px] font-medium uppercase tracking-wider px-2 py-0.5 rounded-full bg-bg-muted/60 text-text-muted border border-border-subtle';
                            ?>
                            <tr class="hover:bg-bg-subtle/30 transition-colors">
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <div class="flex flex-col gap-1.5">
                                        <!-- Nombre cliente: grande, peso semibold, color dominante + dot pulsante si activo -->
                                        <div class="flex items-center gap-2.5">
                                            <span class="text-base font-semibold text-white leading-tight">
                                                <?php echo htmlspecialchars($p['client_name']); ?>
                                            </span>
                                            <?php if ($as && $as['activo_ahora']): ?>
                                                <!-- Fix 10: dot pulsante en lugar de chip 'En vivo' -->
                                                <a href="admin_feedback.php?propuesta_id=<?php echo (int)$p['id']; ?>"
                                                   title="En vivo ahora mismo" aria-label="En vivo"
                                                   class="relative inline-flex w-2.5 h-2.5">
                                                    <span class="absolute inline-flex w-full h-full rounded-full bg-red-400 opacity-60 animate-ping"></span>
                                                    <span class="relative inline-flex w-2.5 h-2.5 rounded-full bg-red-400"></span>
                                                </a>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Chips en 3 tiers — subordinadas al nombre -->
                                        <div class="flex flex-wrap gap-1.5 items-center">
                                            <!-- Tier 1: URGENTE (rojo) -->
                                            <?php if ($as && $as['intento_firma']): ?>
                                                <a href="admin_feedback.php?propuesta_id=<?php echo (int)$p['id']; ?>"
                                                   class="<?= $tier1 ?>"
                                                   title="Abrió el modal de firma pero no completó">
                                                    Intentó firmar
                                                </a>
                                            <?php endif; ?>

                                            <!-- Tier 2: INTERESANTE (mint) -->
                                            <?php if ($as && $as['vio_presupuesto']): ?>
                                                <span class="<?= $tier2 ?>" title="El cliente ha abierto la sección de presupuesto">
                                                    Vio precio
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($as && $as['sesiones_24h'] >= 3): ?>
                                                <span class="<?= $tier2 ?>" title="<?= $as['sesiones_24h'] ?> sesiones en las últimas 24h — muy activo">
                                                    <?= $as['sesiones_24h'] ?>× hoy
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($cs['open'] > 0): ?>
                                                <a href="admin_feedback.php?propuesta_id=<?php echo (int)$p['id']; ?>"
                                                   class="<?= $tier2 ?> hover:bg-tp-primary/20"
                                                   title="<?= $cs['open'] ?> hilo<?= $cs['open'] === 1 ? '' : 's' ?> abierto<?= $cs['open'] === 1 ? '' : 's' ?>">
                                                    <i data-lucide="message-circle" class="w-3 h-3"></i> <?= $cs['open'] ?>
                                                </a>
                                            <?php endif; ?>

                                            <!-- Tier 3: INFO (gris) -->
                                            <?php if ($coldDays !== null): ?>
                                                <span class="<?= $tier3 ?>" title="Última apertura hace <?= $coldDays ?> días">
                                                    <?= $coldDays ?>d sin abrir
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($cs['drafts'] > 0): ?>
                                                <a href="admin_feedback.php?propuesta_id=<?php echo (int)$p['id']; ?>"
                                                   class="<?= $tier3 ?> hover:bg-bg-muted"
                                                   title="<?= $cs['drafts'] ?> borrador<?= $cs['drafts'] === 1 ? '' : 'es' ?> sin publicar">
                                                    <i data-lucide="edit-3" class="w-3 h-3"></i> <?= $cs['drafts'] ?>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($cs['pending_notify'] > 0 && $cs['drafts'] === 0): ?>
                                                <a href="admin_feedback.php?propuesta_id=<?php echo (int)$p['id']; ?>"
                                                   class="<?= $tier3 ?> hover:bg-bg-muted"
                                                   title="<?= $cs['pending_notify'] ?> respuesta<?= $cs['pending_notify'] === 1 ? '' : 's' ?> sin avisar al cliente">
                                                    <i data-lucide="mail" class="w-3 h-3"></i> <?= $cs['pending_notify'] ?>
                                                </a>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Metadata: actividad + sesiones (más tenue, sin uppercase) -->
                                        <div class="text-xs text-text-muted">
                                            <?php
                                            if ($as && $as['last_event_at']) {
                                                $diff = time() - strtotime($as['last_event_at']);
                                                if ($diff < 120) echo 'Activo hace ' . $diff . 's';
                                                elseif ($diff < 3600) echo 'Activo hace ' . (int)($diff/60) . 'min';
                                                elseif ($diff < 86400) echo 'Activo hace ' . (int)($diff/3600) . 'h';
                                                else echo 'Activo ' . date('d/m/y H:i', strtotime($as['last_event_at']));
                                                echo ' · ' . $as['sesiones'] . ' ' . ($as['sesiones'] === 1 ? 'sesión' : 'sesiones');
                                            } elseif ($p['last_accessed_at']) {
                                                echo 'Visto: ' . date('d/m/y H:i', strtotime($p['last_accessed_at']));
                                            } else {
                                                echo 'Sin aperturas';
                                            }
                                            ?>
                                        </div>
                                    <?php $ps = $providerStats[(int)$p['id']] ?? null; if ($ps && !empty($ps['shelf'])): ?>
                                        <?php
                                        // Iter 2 · Provider shelf: avatars + dot por estado del último presupuesto
                                        $stateLabel = [
                                            'recibido' => 'recibido sin decisión',
                                            'en_revision' => 'en revisión',
                                            'aceptado' => 'aceptado',
                                            'rechazado' => 'rechazado',
                                            'iteracion_solicitada' => 'iteración pedida',
                                            'sin_budget' => 'sin presupuesto',
                                        ];
                                        $stateDotClass = [
                                            'recibido' => 'tp-shelf-dot--yellow',
                                            'en_revision' => 'tp-shelf-dot--amber',
                                            'aceptado' => 'tp-shelf-dot--mint',
                                            'rechazado' => 'tp-shelf-dot--red',
                                            'iteracion_solicitada' => 'tp-shelf-dot--purple',
                                            'sin_budget' => 'tp-shelf-dot--dashed',
                                        ];
                                        ?>
                                        <div class="mt-2 pt-1.5 border-t border-border-subtle">
                                            <div class="flex items-center gap-1.5 mb-1">
                                                <i data-lucide="hard-hat" class="w-3 h-3 text-purple-300/80"></i>
                                                <span class="text-[10px] font-semibold uppercase tracking-wider text-text-muted/70">Proveedores</span>
                                            </div>
                                            <div class="flex items-center gap-1.5 flex-wrap">
                                                <?php foreach ($ps['shelf'] as $pv):
                                                    $tipBits = [htmlspecialchars($pv['nombre'])];
                                                    if ($pv['empresa']) $tipBits[] = htmlspecialchars($pv['empresa']);
                                                    if ($pv['version']) $tipBits[] = 'v' . $pv['version'];
                                                    if ($pv['importe']) $tipBits[] = number_format($pv['importe'], 2, ',', '.') . ' €';
                                                    $tipBits[] = $stateLabel[$pv['state']] ?? $pv['state'];
                                                    if ($pv['days_since'] !== null && $pv['days_since'] > 0) {
                                                        $tipBits[] = 'hace ' . $pv['days_since'] . ' d';
                                                    }
                                                    $tip = implode(' · ', $tipBits);
                                                ?>
                                                    <a href="admin_providers.php?proveedor_id=<?= (int)$pv['id'] ?>"
                                                       class="tp-shelf-avatar"
                                                       title="<?= $tip ?>"
                                                       aria-label="<?= $tip ?>">
                                                        <?= htmlspecialchars($pv['inicial']) ?>
                                                        <span class="tp-shelf-dot <?= $stateDotClass[$pv['state']] ?? '' ?>" aria-hidden="true">
                                                            <?php if ($pv['state'] === 'aceptado'): ?>
                                                                <svg viewBox="0 0 24 24" fill="none" stroke="#000" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" style="width:6px;height:6px;display:block;"><polyline points="20 6 9 17 4 12"/></svg>
                                                            <?php endif; ?>
                                                        </span>
                                                    </a>
                                                <?php endforeach; ?>
                                                <a href="admin_providers.php?propuesta_id=<?= (int)$p['id'] ?>"
                                                   class="tp-shelf-invite"
                                                   title="Invitar proveedor a <?= htmlspecialchars($p['client_name']) ?>"
                                                   aria-label="Invitar proveedor">
                                                    <i data-lucide="plus" class="w-3 h-3"></i>
                                                </a>
                                            </div>
                                        </div>
                                    <?php elseif ($ps): ?>
                                        <!-- Propuesta sin proveedores invitados: silencio, solo botón discreto en hover -->
                                        <a href="admin_providers.php?propuesta_id=<?= (int)$p['id'] ?>"
                                           class="inline-flex items-center gap-1 mt-2 text-[10px] text-text-muted/40 hover:text-purple-300 transition-colors"
                                           title="Invitar primer proveedor">
                                            <i data-lucide="plus" class="w-3 h-3"></i> Invitar proveedor
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <!-- Col 2 · DOCUMENTO (URL + PIN + Versión + fecha envío, stacked) -->
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <div class="flex flex-col gap-1.5">
                                        <div class="flex items-center gap-2 group">
                                            <a href="<?= htmlspecialchars($base_path) ?>/p/<?= htmlspecialchars($p['slug']) ?>"
                                               target="_blank"
                                               class="text-xs font-mono text-text-secondary hover:text-tp-primary transition-colors flex items-center gap-1">
                                                /p/<?= htmlspecialchars($p['slug']) ?>
                                                <i data-lucide="external-link" class="w-3 h-3 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                                            </a>
                                            <button onclick="copyToClipboard('<?= 'https://' . $_SERVER['HTTP_HOST'] . $base_path . '/p/' . $p['slug'] ?>')"
                                                    class="text-text-muted hover:text-white opacity-0 group-hover:opacity-100 transition-all"
                                                    title="Copiar URL">
                                                <i data-lucide="copy" class="w-3 h-3"></i>
                                            </button>
                                        </div>
                                        <div class="flex items-center gap-2 text-xs">
                                            <span class="bg-bg-base border border-border-base px-2 py-0.5 rounded-md font-mono text-tp-primary text-[11px]"><?= htmlspecialchars($p['pin']) ?></span>
                                            <span class="font-semibold text-white"><?= htmlspecialchars($p['version'] ?? 'v1.0') ?></span>
                                            <span class="text-text-muted">·</span>
                                            <span class="text-text-muted"><?= $p['sent_date'] ? date('d/m/y', strtotime($p['sent_date'])) : '--/--/--' ?></span>
                                        </div>
                                    </div>
                                </td>

                                <!-- Col 3 · ESTADO (toggles Doc/Pres/IA + Holded/PDF + Status on/off) -->
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <?php
                                    $doc_approved = isset($approvals_map[$p['id']]['documento_funcional']);
                                    $pres_approved = isset($approvals_map[$p['id']]['presupuesto']);
                                    $jordan_on = !empty($p['enable_ai_assistant']);
                                    $holdedLink = $holdedMap[(int)$p['id']] ?? null;
                                    $toggleBase = "w-9 h-5 bg-bg-base border border-border-base rounded-full peer peer-checked:after:translate-x-[16px] after:content-[''] after:absolute after:top-[3px] after:left-[3px] after:bg-text-muted after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:after:bg-bg-base peer-checked:bg-tp-primary peer-checked:border-tp-primary";
                                    ?>
                                    <div class="flex items-center gap-5 justify-center flex-wrap">
                                        <!-- Toggles Doc · Pres · IA compactos en una fila -->
                                        <div class="flex items-center gap-3">
                                            <label class="relative inline-flex items-center cursor-pointer gap-1.5" title="Aprobado: Documento funcional">
                                                <input type="checkbox" class="sr-only peer"
                                                       onchange="toggleApproval(<?= $p['id'] ?>, 'documento_funcional', this.checked, this)"
                                                       <?= $doc_approved ? 'checked' : '' ?>>
                                                <div class="<?= $toggleBase ?>"></div>
                                                <span class="text-[10px] font-semibold uppercase tracking-wider <?= $doc_approved ? 'text-tp-primary' : 'text-text-muted' ?>">Doc</span>
                                            </label>
                                            <label class="relative inline-flex items-center cursor-pointer gap-1.5" title="Aprobado: Presupuesto">
                                                <input type="checkbox" class="sr-only peer"
                                                       onchange="toggleApproval(<?= $p['id'] ?>, 'presupuesto', this.checked, this)"
                                                       <?= $pres_approved ? 'checked' : '' ?>>
                                                <div class="<?= $toggleBase ?>"></div>
                                                <span class="text-[10px] font-semibold uppercase tracking-wider <?= $pres_approved ? 'text-tp-primary' : 'text-text-muted' ?>">Pres</span>
                                            </label>
                                            <label class="relative inline-flex items-center cursor-pointer gap-1.5" title="Jordan IA activo en /p/<?= htmlspecialchars($p['slug']) ?>">
                                                <input type="checkbox" class="sr-only peer"
                                                       onchange="toggleJordan(<?= $p['id'] ?>, this.checked, this)"
                                                       <?= $jordan_on ? 'checked' : '' ?>>
                                                <div class="<?= $toggleBase ?>"></div>
                                                <span class="text-[10px] font-semibold uppercase tracking-wider <?= $jordan_on ? 'text-tp-primary' : 'text-text-muted' ?>">IA</span>
                                            </label>
                                        </div>

                                        <!-- Holded / PDF presupuesto (separador visual vía border-l) -->
                                        <div class="flex items-center gap-2 border-l border-border-subtle pl-5">
                                            <?php if ($holdedLink): ?>
                                                <span class="inline-flex items-center gap-1 text-[11px] text-tp-primary" title="Vinculado con Holded">
                                                    <i data-lucide="link-2" class="w-3 h-3"></i>
                                                    <?= htmlspecialchars($holdedLink['docNumber']) ?>
                                                </span>
                                                <button onclick="holdedSync(<?= $p['id'] ?>)" class="text-text-muted hover:text-tp-primary" title="Re-sincronizar">
                                                    <i data-lucide="refresh-cw" class="w-3 h-3"></i>
                                                </button>
                                                <button onclick="holdedUnlink(<?= $p['id'] ?>)" class="text-red-500/60 hover:text-red-400" title="Desvincular">
                                                    <i data-lucide="unlink" class="w-3 h-3"></i>
                                                </button>
                                            <?php elseif (!empty($p['presupuesto_pdf'])): ?>
                                                <span class="inline-flex items-center gap-1 text-[11px] text-tp-primary">
                                                    <i data-lucide="file-text" class="w-3 h-3"></i> PDF
                                                </span>
                                                <button onclick="confirmDeletePdf(<?= $p['id'] ?>)" class="text-red-500/60 hover:text-red-400" title="Borrar PDF">
                                                    <i data-lucide="trash-2" class="w-3 h-3"></i>
                                                </button>
                                            <?php else: ?>
                                                <button onclick="openHoldedLink(<?= $p['id'] ?>, '<?= addslashes($p['client_name']) ?>')"
                                                        class="text-[10px] font-semibold uppercase tracking-wider text-tp-primary hover:text-white transition-colors inline-flex items-center gap-1">
                                                    <i data-lucide="link-2" class="w-3 h-3"></i> Holded
                                                </button>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Status Online/Offline al final -->
                                        <label class="relative inline-flex items-center cursor-pointer gap-1.5 border-l border-border-subtle pl-5" title="Propuesta online/offline">
                                            <input type="checkbox" class="sr-only peer"
                                                   onchange="toggleStatus(<?= $p['id'] ?>, this.checked)"
                                                   <?= $p['status']==1 ? 'checked' : '' ?>>
                                            <div class="<?= $toggleBase ?>"></div>
                                            <span class="text-[10px] font-semibold uppercase tracking-wider <?= $p['status']==1 ? 'text-tp-primary' : 'text-text-muted' ?>">Live</span>
                                        </label>
                                    </div>
                                </td>

                                <!-- Col 4 · TRÁFICO (vistas) -->
                                <td class="px-6 py-5 whitespace-nowrap text-center">
                                    <div class="flex items-center gap-2 justify-center">
                                        <span class="font-heading font-bold text-xl text-white leading-none"><?= $p['views_count'] ?></span>
                                        <button onclick="resetViews(<?= $p['id'] ?>, '<?= addslashes($p['client_name']) ?>')"
                                                class="text-tp-primary/40 hover:text-tp-primary transition-colors"
                                                title="Resetear visitas">
                                            <i data-lucide="refresh-cw" class="w-3 h-3"></i>
                                        </button>
                                    </div>
                                    <div class="text-[10px] text-text-muted mt-0.5">vistas</div>
                                </td>

                                <!-- Col 5 · ACCIONES -->
                                <td class="px-6 py-5 whitespace-nowrap text-end">
                                    <div class="flex items-center justify-end gap-2">
                                        <button onclick="editProposal(JSON.parse(this.dataset.proposal))"
                                                data-proposal="<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>"
                                                class="text-text-secondary hover:text-tp-primary transition-colors p-1.5 rounded hover:bg-bg-subtle"
                                                title="Editar propuesta">
                                            <i data-lucide="edit-3" class="w-4 h-4"></i>
                                        </button>
                                        <button onclick="confirmDeleteProposal(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['client_name']), ENT_QUOTES, 'UTF-8') ?>)"
                                                class="text-red-500/60 hover:text-red-400 transition-colors p-1.5 rounded hover:bg-red-500/10"
                                                title="Borrar propuesta">
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
    </div><!-- /.admin-layout -->

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
            // H9: refrescar sidebar para reflejar cambio de archivadas/activas sin full reload
            if (typeof window.tpSidebarRefresh === 'function') {
                window.tpSidebarRefresh();
            }
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

        async function holdedConfirm(force = false) {
            if (!_holdedPreviewDoc) return;
            const pid = +document.getElementById('holded-propuesta-id').value;
            const body = { propuesta_id: pid, holded_id: _holdedPreviewDoc.id };
            if (force) body.confirm_overwrite = true;
            const res = await fetch('admin.php?action=holded_link', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(body),
            });
            const data = await res.json();
            if (data.needs_confirmation) {
                if (confirm(data.message)) return holdedConfirm(true);
                return;
            }
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

<!-- ════════════════════════════════════════════════════════
     ITER 4a · DRAWER LATERAL DEL PROVEEDOR
     Se rellena al click en .tp-shelf-avatar (preventDefault + fetch)
     ════════════════════════════════════════════════════════ -->
<div id="tp-drawer-scrim" class="tp-drawer-scrim" hidden onclick="tpDrawerClose()" aria-hidden="true"></div>
<aside id="tp-provider-drawer" class="tp-provider-drawer" hidden aria-label="Detalle del proveedor" role="dialog" aria-modal="true">
    <div class="tp-drawer-head">
        <div class="tp-drawer-id">
            <div class="tp-drawer-avatar" id="tp-drawer-avatar">—</div>
            <h3 class="tp-drawer-name" id="tp-drawer-name">Cargando…<small id="tp-drawer-sub"></small></h3>
        </div>
        <div class="tp-drawer-actions">
            <a id="tp-drawer-expand" class="tp-drawer-expand" href="#" title="Abrir detalle completo (E)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:11px;height:11px;"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
                Ver completo
            </a>
            <button class="tp-drawer-close" onclick="tpDrawerClose()" aria-label="Cerrar (Esc)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
    </div>
    <div class="tp-drawer-body" id="tp-drawer-body">
        <div class="tp-drawer-loading">Cargando…</div>
    </div>
    <div class="tp-drawer-shortcuts">
        <span><span class="tp-drawer-kbd">Esc</span> cerrar</span>
        <span><span class="tp-drawer-kbd">E</span> ver completo</span>
        <span style="margin-left:auto;opacity:.6;">El dashboard queda intacto al cerrar</span>
    </div>
</aside>

<style>
/* ── Iter 4a · Drawer del proveedor desde el dashboard ── */
.tp-drawer-scrim {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.55);
    backdrop-filter: blur(2px);
    z-index: 999;
    animation: tpDrawerFadeIn .15s ease-out;
}
@keyframes tpDrawerFadeIn { from { opacity: 0; } to { opacity: 1; } }
.tp-drawer-scrim[hidden] { display: none; }

.tp-provider-drawer {
    position: fixed;
    top: 0; right: 0; bottom: 0;
    width: 520px;
    max-width: 95vw;
    background: #141414;
    border-left: 1px solid #2a2a2a;
    box-shadow: -20px 0 50px rgba(0,0,0,.5);
    z-index: 1000;
    display: flex;
    flex-direction: column;
    animation: tpDrawerSlide .25s ease-out;
    color: #f5f5f5;
    font: 14px/1.55 'Inter', system-ui, sans-serif;
}
@keyframes tpDrawerSlide { from { transform: translateX(100%); } to { transform: translateX(0); } }
.tp-provider-drawer[hidden] { display: none; }

.tp-drawer-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    border-bottom: 1px solid #1f1f1f;
    background: #191919;
    gap: 12px;
}
.tp-drawer-id { display: flex; align-items: center; gap: 10px; }
.tp-drawer-avatar {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: rgba(192, 132, 252, .12);
    color: #c084fc;
    border: 1px solid rgba(192, 132, 252, .35);
    display: grid;
    place-items: center;
    font-size: 13px;
    font-weight: 700;
}
.tp-drawer-name {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 15px;
    font-weight: 600;
    letter-spacing: -.005em;
    margin: 0;
}
.tp-drawer-name small {
    display: block;
    color: #8a8a8a;
    font-size: 11px;
    margin-top: 2px;
    font-weight: 400;
    font-family: 'Inter', sans-serif;
}
.tp-drawer-actions { display: flex; gap: 6px; align-items: center; }
.tp-drawer-close, .tp-drawer-expand {
    background: transparent;
    border: 1px solid #1f1f1f;
    color: #8a8a8a;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 10px;
    border-radius: 5px;
    font-family: inherit;
    font-size: 11.5px;
    font-weight: 500;
    text-decoration: none;
    transition: all .12s ease;
}
.tp-drawer-close { padding: 6px; }
.tp-drawer-close:hover, .tp-drawer-expand:hover { color: #f5f5f5; border-color: #2a2a2a; }
.tp-drawer-expand:hover { color: #5dffbf; border-color: rgba(93,255,191,.4); }

.tp-drawer-body {
    flex: 1;
    overflow-y: auto;
    padding: 18px;
}
.tp-drawer-loading {
    color: #8a8a8a;
    text-align: center;
    padding: 40px 0;
    font-size: 13px;
}

.tp-drawer-section { margin-bottom: 18px; }
.tp-drawer-section-title {
    font-size: 10.5px;
    font-weight: 600;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: #8a8a8a;
    margin-bottom: 8px;
}

.tp-drawer-budget {
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: 12px;
    align-items: start;
    padding: 14px;
    background: #0e0e0e;
    border: 1px solid #2a2a2a;
    border-radius: 8px;
    position: relative;
}
.tp-drawer-budget::before {
    content: "";
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 3px;
    border-radius: 8px 0 0 8px;
    background: var(--tp-state-color, #ffd84d);
}
.tp-drawer-budget-v {
    background: #1f1f1f;
    border: 1px solid #1f1f1f;
    padding: 4px 8px;
    border-radius: 5px;
    font-size: 11.5px;
    font-weight: 700;
    text-align: center;
    min-width: 32px;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-variant-numeric: tabular-nums;
}
.tp-drawer-budget-info { min-width: 0; }
.tp-drawer-budget-filename {
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 5px;
    letter-spacing: -.005em;
    word-break: break-word;
}
.tp-drawer-budget-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    font-size: 12px;
    color: #b3b3b3;
    font-variant-numeric: tabular-nums;
}
.tp-drawer-budget-meta strong { color: #f5f5f5; }
.tp-drawer-budget-meta-sep { color: #8a8a8a; opacity: .5; }
.tp-drawer-budget-pdf {
    background: #5dffbf;
    color: #000;
    border: none;
    padding: 7px 11px;
    border-radius: 5px;
    font-weight: 700;
    font-size: 11.5px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
    height: fit-content;
    transition: background .12s ease;
}
.tp-drawer-budget-pdf:hover { background: #49e6a8; }

.tp-drawer-state-row {
    margin-top: 12px;
    padding-top: 10px;
    border-top: 1px dashed #1f1f1f;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
    grid-column: 1 / -1;
}
.tp-drawer-state-actions {
    display: flex;
    gap: 4px;
    margin-left: auto;
    flex-wrap: wrap;
}
.tp-drawer-state-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 10px;
    border-radius: 5px;
    background: transparent;
    border: 1px solid #1f1f1f;
    color: #b3b3b3;
    font-family: inherit;
    font-size: 11px;
    font-weight: 500;
    cursor: pointer;
    transition: all .12s ease;
}
.tp-drawer-state-btn:hover { background: rgba(255,255,255,.03); color: #f5f5f5; }
.tp-drawer-state-btn--review { color: #fac850; border-color: rgba(250,200,80,.25); }
.tp-drawer-state-btn--review:hover { background: rgba(250,200,80,.08); }
.tp-drawer-state-btn--accept { color: #5dffbf; border-color: rgba(93,255,191,.25); }
.tp-drawer-state-btn--accept:hover { background: rgba(93,255,191,.08); }
.tp-drawer-state-btn--iterate { color: #c084fc; border-color: rgba(192,132,252,.25); }
.tp-drawer-state-btn--iterate:hover { background: rgba(192,132,252,.08); }

/* Estado pill — copia minimal */
.tp-drawer .state-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 10.5px;
    font-weight: 600;
    letter-spacing: .02em;
    border: 1px solid;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}
.tp-state-recibido { background: rgba(255,216,77,.1); color: #ffd84d; border: 1px solid rgba(255,216,77,.35); padding: 3px 9px 3px 14px; border-radius: 999px; font-size: 10.5px; font-weight: 600; position: relative; display: inline-flex; align-items: center; }
.tp-state-recibido::before { content: ""; position: absolute; left: 6px; top: 50%; transform: translateY(-50%); width: 6px; height: 6px; border-radius: 50%; background: #ffd84d; animation: tpDrawerPulseY 1.6s ease-in-out infinite; }
@keyframes tpDrawerPulseY { 0%, 100% { box-shadow: 0 0 0 0 rgba(255,216,77,.55); } 70% { box-shadow: 0 0 0 5px rgba(255,216,77,0); } }
.tp-state-revision { background: rgba(250,200,80,.1); color: #fac850; border: 1px solid rgba(250,200,80,.35); padding: 3px 9px; border-radius: 999px; font-size: 10.5px; font-weight: 600; }
.tp-state-aceptado { background: rgba(93,255,191,.12); color: #5dffbf; border: 1px solid rgba(93,255,191,.35); padding: 3px 9px; border-radius: 999px; font-size: 10.5px; font-weight: 600; }
.tp-state-rechazado { background: rgba(255,107,107,.1); color: #ff6b6b; border: 1px solid rgba(255,107,107,.35); padding: 3px 9px; border-radius: 999px; font-size: 10.5px; font-weight: 600; }
.tp-state-iteracion { background: rgba(192,132,252,.1); color: #c084fc; border: 1px solid rgba(192,132,252,.35); padding: 3px 9px; border-radius: 999px; font-size: 10.5px; font-weight: 600; }
.tp-state-since { font-size: 11px; color: #8a8a8a; font-variant-numeric: tabular-nums; }

.tp-drawer-note-display {
    width: 100%;
    margin-top: 4px;
    padding: 8px 12px;
    background: rgba(192,132,252,.05);
    border-left: 2px solid #c084fc;
    border-radius: 4px;
    font-size: 12px;
    color: #b3b3b3;
    line-height: 1.5;
}
.tp-drawer-note-head {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: #c084fc;
    margin-bottom: 4px;
}

.tp-drawer-quick-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}
.tp-drawer-quick {
    background: #0e0e0e;
    border: 1px solid #1f1f1f;
    border-radius: 6px;
    padding: 10px 12px;
    font-size: 12px;
}
.tp-drawer-quick small {
    display: block;
    color: #8a8a8a;
    text-transform: uppercase;
    letter-spacing: .06em;
    font-size: 9.5px;
    font-weight: 600;
    margin-bottom: 2px;
}
.tp-drawer-quick strong {
    font-family: 'Plus Jakarta Sans', sans-serif;
    color: #f5f5f5;
    font-weight: 600;
    font-size: 13px;
    font-variant-numeric: tabular-nums;
}
.tp-drawer-empty {
    color: #8a8a8a;
    text-align: center;
    padding: 18px 12px;
    border: 1px dashed #1f1f1f;
    border-radius: 6px;
    font-size: 12px;
}

.tp-drawer-shortcuts {
    padding: 10px 18px;
    background: #191919;
    border-top: 1px solid #1f1f1f;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    font-size: 11px;
    color: #8a8a8a;
    align-items: center;
}
.tp-drawer-kbd {
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: 10px;
    background: #1f1f1f;
    border: 1px solid #1f1f1f;
    border-radius: 3px;
    padding: 1px 5px;
    color: #b3b3b3;
    font-weight: 600;
}
</style>

<script>
/* ════════════════════════════════════════════════════════
   ITER 4a · Drawer logic + sibling nav (J/K) y atajos
   ════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    const STATE_LABEL = {
        recibido: 'Recibido', en_revision: 'En revisión',
        aceptado: 'Aceptado', rechazado: 'Rechazado',
        iteracion_solicitada: 'Iteración pedida',
    };
    const STATE_CLASS = {
        recibido: 'tp-state-recibido', en_revision: 'tp-state-revision',
        aceptado: 'tp-state-aceptado', rechazado: 'tp-state-rechazado',
        iteracion_solicitada: 'tp-state-iteracion',
    };
    const STATE_COLOR = {
        recibido: '#ffd84d', en_revision: '#fac850',
        aceptado: '#5dffbf', rechazado: '#ff6b6b',
        iteracion_solicitada: '#c084fc',
    };

    function esc(s) { return (s || '').toString().replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    function fmtAmount(n) { return n !== null && n !== undefined ? new Intl.NumberFormat('es-ES', {minimumFractionDigits:2, maximumFractionDigits:2}).format(n) + ' €' : '—'; }
    function fmtDate(s) {
        if (!s) return '';
        const d = new Date(s.replace(' ', 'T') + (s.includes('Z') ? '' : 'Z'));
        if (isNaN(d)) return s;
        return d.toLocaleString('es-ES', {day:'2-digit', month:'2-digit', year:'2-digit', hour:'2-digit', minute:'2-digit'});
    }
    function daysAgo(s) {
        if (!s) return null;
        const d = new Date(s.replace(' ', 'T') + (s.includes('Z') ? '' : 'Z'));
        if (isNaN(d)) return null;
        const days = Math.floor((Date.now() - d.getTime()) / 86400000);
        if (days < 1) return 'hoy';
        if (days === 1) return 'ayer';
        return 'hace ' + days + ' d';
    }

    let currentProviderId = null;
    let isOpen = false;

    window.tpDrawerOpen = async function (proveedorId) {
        currentProviderId = proveedorId;
        const scrim = document.getElementById('tp-drawer-scrim');
        const drawer = document.getElementById('tp-provider-drawer');
        const body = document.getElementById('tp-drawer-body');
        body.innerHTML = '<div class="tp-drawer-loading">Cargando…</div>';
        scrim.hidden = false;
        drawer.hidden = false;
        isOpen = true;

        try {
            const r = await fetch('admin_providers.php?action=drawer_data&proveedor_id=' + encodeURIComponent(proveedorId), { credentials: 'same-origin' }).then(r => r.json());
            if (!r.success) { body.innerHTML = '<div class="tp-drawer-loading">' + esc(r.error || 'Error') + '</div>'; return; }
            renderDrawer(r);
        } catch (err) {
            body.innerHTML = '<div class="tp-drawer-loading">Error de red</div>';
        }
    };

    window.tpDrawerClose = function () {
        document.getElementById('tp-drawer-scrim').hidden = true;
        document.getElementById('tp-provider-drawer').hidden = true;
        isOpen = false;
        currentProviderId = null;
    };

    function renderDrawer(data) {
        const pv = data.provider;
        const latest = data.budgets[0] || null;
        const past = data.budgets.slice(1);

        // Cabecera
        document.getElementById('tp-drawer-avatar').textContent = (pv.nombre || '?').charAt(0).toUpperCase();
        const sub = (pv.empresa ? pv.empresa + ' · ' : '') + 'en ' + pv.client_name;
        document.getElementById('tp-drawer-name').innerHTML = esc(pv.nombre) + '<small>' + esc(sub) + '</small>';
        document.getElementById('tp-drawer-expand').setAttribute('href', 'admin_providers.php?proveedor_id=' + pv.id);

        const body = document.getElementById('tp-drawer-body');

        if (!latest) {
            body.innerHTML = `
                <div class="tp-drawer-section">
                    <div class="tp-drawer-section-title">Último presupuesto</div>
                    <div class="tp-drawer-empty">
                        <strong style="color:#b3b3b3;display:block;margin-bottom:4px;">Aún sin presupuesto</strong>
                        ${esc(pv.nombre)} recibirá un aviso al subir su primera versión.
                    </div>
                </div>
                ${renderResumen(pv, data)}
            `;
            return;
        }

        const state = latest.decision_state || 'recibido';
        const stateCol = STATE_COLOR[state] || '#ffd84d';
        const stateCls = STATE_CLASS[state] || 'tp-state-recibido';
        const stateLbl = STATE_LABEL[state] || state;
        const sinceText = latest.decision_at
            ? (daysAgo(latest.decision_at) + ' · ' + stateLbl.toLowerCase())
            : (daysAgo(latest.uploaded_at) + ' · sin decisión');

        // Botones contextuales
        const btns = [];
        if (state !== 'en_revision' && state !== 'aceptado') {
            btns.push(`<button class="tp-drawer-state-btn tp-drawer-state-btn--review" onclick="tpDrawerSetState('en_revision')">Revisar</button>`);
        }
        if (state !== 'iteracion_solicitada') {
            btns.push(`<button class="tp-drawer-state-btn tp-drawer-state-btn--iterate" onclick="tpDrawerSetState('iteracion_solicitada')">Iterar</button>`);
        }
        if (state !== 'aceptado') {
            btns.push(`<button class="tp-drawer-state-btn tp-drawer-state-btn--accept" onclick="tpDrawerSetState('aceptado')">Aceptar</button>`);
        }

        const noteBlock = latest.decision_note ? `
            <div class="tp-drawer-note-display">
                <div class="tp-drawer-note-head">Nota a ${esc((pv.nombre || '').split(' ')[0])} · ${fmtDate(latest.decision_at || latest.uploaded_at)}</div>
                ${esc(latest.decision_note).replace(/\n/g, '<br>')}
            </div>` : '';

        const meta = [];
        if (latest.importe_total !== null) meta.push('<strong>' + fmtAmount(latest.importe_total) + '</strong>');
        if (latest.plazo_dias !== null) meta.push((latest.plazo_dias|0) + ' d');
        meta.push(fmtDate(latest.uploaded_at));

        body.innerHTML = `
            <div class="tp-drawer-section">
                <div class="tp-drawer-section-title">Último presupuesto</div>
                <div class="tp-drawer-budget" style="--tp-state-color:${stateCol};">
                    <div class="tp-drawer-budget-v">v${latest.version_num|0}</div>
                    <div class="tp-drawer-budget-info">
                        <div class="tp-drawer-budget-filename">${esc(latest.archivo_nombre)}</div>
                        <div class="tp-drawer-budget-meta">${meta.join('<span class="tp-drawer-budget-meta-sep">·</span>')}</div>
                    </div>
                    <a href="admin_providers.php?download=${latest.id}" target="_blank" class="tp-drawer-budget-pdf">PDF</a>

                    <div class="tp-drawer-state-row">
                        <span class="${stateCls}">${esc(stateLbl)}</span>
                        <span class="tp-state-since">${esc(sinceText)}</span>
                        <div class="tp-drawer-state-actions">${btns.join('')}</div>
                        ${noteBlock}
                    </div>
                </div>
                ${past.length ? `<div style="margin-top:10px;color:#8a8a8a;font-size:11.5px;"><strong>${past.length}</strong> versi${past.length === 1 ? 'ón' : 'ones'} anterior${past.length === 1 ? '' : 'es'} · ver en detalle completo</div>` : ''}
            </div>
            ${renderResumen(pv, data)}
        `;
    }

    function renderResumen(pv, data) {
        return `
            <div class="tp-drawer-section">
                <div class="tp-drawer-section-title">Resumen</div>
                <div class="tp-drawer-quick-grid">
                    <div class="tp-drawer-quick"><small>Accesos</small><strong>${pv.accesos|0}</strong></div>
                    <div class="tp-drawer-quick"><small>Mensajes</small><strong>${data.messages_count|0} hilo${data.messages_count === 1 ? '' : 's'}</strong></div>
                    <div class="tp-drawer-quick"><small>Último acceso</small><strong>${pv.last_accessed_at ? daysAgo(pv.last_accessed_at) : '—'}</strong></div>
                    <div class="tp-drawer-quick"><small>Permiso cliente</small><strong style="color:${(pv.ver_comentarios|0) === 1 ? '#5dffbf' : '#8a8a8a'};">${(pv.ver_comentarios|0) === 1 ? 'Ve comentarios' : 'Sin acceso'}</strong></div>
                </div>
            </div>
        `;
    }

    // Setear estado desde el drawer (reusa endpoint admin_providers set_budget_state)
    window.tpDrawerSetState = async function (state) {
        if (!currentProviderId) return;
        const data = await fetch('admin_providers.php?action=drawer_data&proveedor_id=' + currentProviderId, { credentials: 'same-origin' }).then(r => r.json());
        const latest = data?.budgets?.[0];
        if (!latest) { alert('No hay presupuesto vigente'); return; }
        const note = (state === 'iteracion_solicitada' || state === 'rechazado' || state === 'aceptado')
            ? (prompt('Nota opcional para el proveedor (Enter para omitir):') || '')
            : '';
        const notify = (state === 'aceptado' || state === 'rechazado' || state === 'iteracion_solicitada');
        const body = new URLSearchParams({ action: 'set_budget_state', budget_id: latest.id, state, note });
        if (notify) body.append('notify_provider', '1');
        const r = await fetch('admin_providers.php', { method: 'POST', body, credentials: 'same-origin' }).then(r => r.json());
        if (r.success) tpDrawerOpen(currentProviderId); // re-render
        else alert(r.error || 'Error');
    };

    // Intercepta click en cualquier .tp-shelf-avatar del dashboard
    document.addEventListener('click', function (e) {
        const a = e.target.closest('a.tp-shelf-avatar');
        if (!a) return;
        if (e.metaKey || e.ctrlKey || e.shiftKey) return; // permitir abrir en pestaña nueva
        e.preventDefault();
        const href = a.getAttribute('href') || '';
        const m = href.match(/proveedor_id=(\d+)/);
        if (m) tpDrawerOpen(parseInt(m[1], 10));
    });

    // Atajos teclado
    document.addEventListener('keydown', function (e) {
        if (!isOpen) return;
        if (e.key === 'Escape') { tpDrawerClose(); }
        else if (e.key === 'e' || e.key === 'E') {
            const expand = document.getElementById('tp-drawer-expand');
            if (expand) window.location.href = expand.getAttribute('href');
        }
    });
})();
</script>

</body>

</html>