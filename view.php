<?php
session_start();
require_once __DIR__ . '/config.php';
$base_path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
if ($base_path === '/' || $base_path === '\\') {
    $base_path = '';
}
// Fix: bajo PHP built-in server con router.php, PHP_SELF incluye /p/{slug}/view.php
// y dirname devuelve /p/{slug}, que luego duplica /p/ en el redirect. Forzar vacío en dev.
if (php_sapi_name() === 'cli-server' || strpos($base_path, '/p/') !== false) {
    $base_path = '';
}

/**
 * Envia notificacion por Telegram usando las credenciales de config.php.
 * No bloquea si Telegram falla (timeout 3s, errores silenciosos).
 */
function sendTelegramNotification($text) {
    if (!defined('TELEGRAM_BOT_TOKEN') || !defined('TELEGRAM_CHAT_ID')) return false;
    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
    $payload = json_encode([
        'chat_id'    => TELEGRAM_CHAT_ID,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $ok = curl_exec($ch);
    curl_close($ch);
    return $ok !== false;
}


if (!isset($_GET['id']) || empty($_GET['id'])) {
    showError("Acceso Denegado", "El enlace no es válido o ha expirado.");
}

$slug = trim($_GET['id']);
$pdo = getDBConnection();

$stmt = $pdo->prepare("SELECT * FROM propuestas WHERE slug = ?");
$stmt->execute([$slug]);
$proposal = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$proposal) {
    showError("No Encontrada", "La propuesta solicitada no existe en nuestros registros.");
}
if ((int)$proposal['status'] === 0) {
    showError("Propuesta Pausada", "Este documento no está disponible temporalmente. Contacta con Tres Puntos.");
}

$session_key = 'auth_proposal_' . $proposal['id'];
$identity_key = 'visitor_identity_' . $proposal['id'];
$login_errors = [];
$login_prefill = ['nombre' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pin'])) {
    $pin    = trim($_POST['pin'] ?? '');
    $nombre = trim($_POST['visitor_nombre'] ?? '');
    $email  = trim($_POST['visitor_email'] ?? '');
    $login_prefill = ['nombre' => $nombre, 'email' => $email];

    if ($nombre === '' || mb_strlen($nombre) < 2) {
        $login_errors['nombre'] = 'Escribe tu nombre para continuar.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $login_errors['email'] = 'Introduce un email válido.';
    }
    if ($pin !== $proposal['pin']) {
        $login_errors['pin'] = 'PIN incorrecto.';
    }

    if (!$login_errors) {
        // Auto-detectar proveedor: si el email coincide con cualquier propuesta_proveedores,
        // marcar la sesión como interna (no es cliente final) y avisar por Telegram.
        $isProvEmail = false;
        $provInfo = null;
        try {
            $stmt = $pdo->prepare("SELECT id, nombre, empresa, propuesta_id FROM propuesta_proveedores WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $stmt->execute([$email]);
            $provInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            $isProvEmail = (bool)$provInfo;
        } catch (Throwable $e) { /* tabla puede no existir en deploys antiguos */ }

        // ⚠️ Alerta: un proveedor está entrando al doc usando el PIN del cliente
        // (lo normal sería que entre por /s/{token}). Eso le da acceso al presupuesto
        // y resto de contenido cara-cliente, hay que saberlo.
        if ($isProvEmail && function_exists('sendTelegramNotification')) {
            $clientName = $proposal['client_name'] ?? $slug;
            $msg = "⚠️ <b>Proveedor con PIN cliente</b>\n";
            $msg .= "Documento: <b>" . htmlspecialchars($clientName) . "</b>\n";
            $msg .= "Proveedor: <b>" . htmlspecialchars($provInfo['nombre'] ?? $nombre) . "</b>";
            if (!empty($provInfo['empresa'])) $msg .= " (" . htmlspecialchars($provInfo['empresa']) . ")";
            $msg .= "\nEmail: " . htmlspecialchars($email) . "\n\n";
            $msg .= "Está viendo el documento como cliente — incluye presupuesto y firmas. Confirma si es intencional.\n\n";
            $msg .= "https://doc.trespuntos-lab.com/p/" . $slug;
            @sendTelegramNotification($msg);
        }

        $_SESSION[$session_key] = true;
        $_SESSION[$identity_key] = [
            'nombre'      => mb_substr($nombre, 0, 120),
            'email'       => mb_substr($email, 0, 180),
            'is_internal' => (isInternalEmail($email) || $isProvEmail) ? 1 : 0,
            'identified_at' => date('Y-m-d H:i:s'),
        ];
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        header("Location: $protocol://" . $_SERVER['HTTP_HOST'] . $base_path . "/p/" . $slug);
        exit;
    }
}

// Desbloqueo requiere AMBOS: PIN validado + identidad registrada en sesión.
// Si un usuario tiene sesión PIN antigua (pre-abril 2026) sin identidad, forzamos re-login.
$is_unlocked = isset($_SESSION[$session_key]) && $_SESSION[$session_key] === true
            && isset($_SESSION[$identity_key]) && is_array($_SESSION[$identity_key]);

// Identidad disponible para el resto del render (comentarios pre-rellenados, firma, tracking)
$visitorIdentity = $_SESSION[$identity_key] ?? null;

// ─── PROVIDER MODE ───────────────────────────────────────────
// Si viene de provider.php autenticado, usa la sesión del proveedor para bypass del PIN gate.
// Indicadores: ?__provider=TOKEN en URL y sesión guardada desde provider.php.
$isProviderMode = false;
$isAdminMode = false;   // Admin viendo el doc con herramientas de respuesta staff
$__provider = null;
if (isset($_GET['__provider'])) {
    $ptoken = preg_replace('/[^a-f0-9]/i', '', $_GET['__provider']);
    if (strlen($ptoken) >= 24) {
        $psess = 'provider_unlocked_' . $ptoken;
        $adminSess = !empty($_SESSION['admin_logged']);
        // Entra si el proveedor ya pasó el PIN, O si el admin pasa ?__admin_view=1
        $allowByAdmin = isset($_GET['__admin_view']) && $adminSess;
        if (!empty($_SESSION[$psess]) || $allowByAdmin) {
            $pq = $pdo->prepare("SELECT * FROM propuesta_proveedores WHERE token = ? AND activo = 1 AND propuesta_id = ?");
            $pq->execute([$ptoken, $proposal['id']]);
            $__provider = $pq->fetch(PDO::FETCH_ASSOC);
            if ($__provider) {
                $isProviderMode = true;
                $is_unlocked = true;
                if ($allowByAdmin) $isAdminMode = true;
            }
        }
    }
}
// Admin mode sobre la vista CLIENTE (ve el doc del cliente y puede responder a sus comentarios como staff)
if (!$isProviderMode && isset($_GET['__admin_view']) && !empty($_SESSION['admin_logged'])) {
    $isAdminMode = true;
    $is_unlocked = true;
}

// Endpoint AJAX: admin responde como staff a un comentario de cliente.
// Solo requiere admin session, no PIN cliente. Por eso se procesa ANTES del PIN gate.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['api_action'] ?? '') === 'staff_reply') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['admin_logged'])) {
        echo json_encode(['success' => false, 'error' => 'Solo admin']);
        exit;
    }
    $parentId = (int)($_POST['parent_id'] ?? 0);
    $texto = trim($_POST['texto'] ?? '');
    if (!$parentId || $texto === '' || mb_strlen($texto) > 4000) {
        echo json_encode(['success' => false, 'error' => 'Datos inválidos']);
        exit;
    }
    $parent = $pdo->prepare("SELECT propuesta_id, section_anchor, section_title FROM comentarios_seccion WHERE id = ? AND propuesta_id = ?");
    $parent->execute([$parentId, $proposal['id']]);
    $p = $parent->fetch(PDO::FETCH_ASSOC);
    if (!$p) { echo json_encode(['success' => false, 'error' => 'No encontrado']); exit; }

    $pdo->prepare("INSERT INTO comentarios_seccion
        (propuesta_id, section_anchor, section_title, autor_nombre, autor_apellidos, autor_email, texto, parent_id, is_staff, is_draft)
        VALUES (?, ?, ?, 'Tres Puntos', '', 'hola@trespuntoscomunicacion.es', ?, ?, 1, 0)")
        ->execute([$p['propuesta_id'], $p['section_anchor'], $p['section_title'], $texto, $parentId]);
    $id = (int)$pdo->lastInsertId();

    $resumen = mb_substr($texto, 0, 120) . (mb_strlen($texto) > 120 ? '…' : '');
    sendTelegramNotification("✅ Respuesta admin a cliente · <b>" . htmlspecialchars($proposal['client_name']) . "</b>\n<i>" . htmlspecialchars($p['section_title'] ?: $p['section_anchor']) . "</i>\n" . htmlspecialchars($resumen));
    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

if ($is_unlocked) {
    $view_timer_key = 'last_view_time_' . $proposal['id'];
    $user_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    // No contar visitas de la IP del administrador
    if ($user_ip !== '85.51.255.66' && (!isset($_SESSION[$view_timer_key]) || (time() - $_SESSION[$view_timer_key] > 300))) {
        $pdo->prepare("UPDATE propuestas SET views_count = views_count + 1, last_accessed_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$proposal['id']]);
        $_SESSION[$view_timer_key] = time();
    }

    $content = $proposal['html_content'];

    // AJAX Handler for approvals — bloquear todos los endpoints de cliente si estamos en provider mode
    if ($isProviderMode && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api_action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Acción no disponible en modo proveedor']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api_action'])) {
        header('Content-Type: application/json');
        $clientName = $proposal['client_name'] ?? '';

        // --- Helpers de firma ligera ---
        // POST tiene prioridad (firma legal explícita con nombre+apellidos).
        // Si no hay POST, cae a la identidad de sesión (PIN gate o proveedor).
        // 'valid' = nombre+apellidos (firma legal). 'valid_lite' = nombre+email (comentarios, tareas).
        $readSigner = function () use ($visitorIdentity, $__provider) {
            $n = trim($_POST['firmante_nombre'] ?? '');
            $a = trim($_POST['firmante_apellidos'] ?? '');
            $e = trim($_POST['firmante_email'] ?? '');

            if ($n === '' && $e === '') {
                if (!empty($visitorIdentity['email'])) {
                    $parts = explode(' ', trim($visitorIdentity['nombre'] ?? ''), 2);
                    $n = $parts[0] ?? '';
                    $a = $parts[1] ?? '';
                    $e = $visitorIdentity['email'];
                } elseif (!empty($__provider['email'])) {
                    $parts = explode(' ', trim($__provider['nombre'] ?? ''), 2);
                    $n = $parts[0] ?? '';
                    $a = $parts[1] ?? '';
                    $e = $__provider['email'];
                }
            }

            return [
                'nombre' => mb_substr($n, 0, 80),
                'apellidos' => mb_substr($a, 0, 120),
                'email' => mb_substr($e, 0, 160),
                'valid' => ($n !== '' && $a !== ''),
                'valid_lite' => ($n !== '' && $e !== ''),
            ];
        };
        $buildHash = function ($propId, $tipo, $signer, $version) {
            $payload = $propId . '|' . $tipo . '|' . $signer['nombre'] . '|' . $signer['apellidos'] . '|' . $version . '|' . date('c');
            return hash('sha256', $payload);
        };

        // Helper: URL pública para deep-link en notificaciones
        $adminFeedbackUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'doc.trespuntos-lab.com') . '/admin_feedback.php?propuesta_id=' . $proposal['id'];
        $viewUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'doc.trespuntos-lab.com') . '/p/' . $slug;

        if ($_POST['api_action'] === 'approve_doc') {
            $signer = $readSigner();
            if (!$signer['valid']) { echo json_encode(['success' => false, 'error' => 'Nombre y apellidos son obligatorios para firmar la aprobación.']); exit; }
            $stmtObj = $pdo->prepare("SELECT COUNT(*) FROM aprobaciones WHERE propuesta_id = ? AND tipo = 'documento_funcional'");
            $stmtObj->execute([$proposal['id']]);
            $isFirst = ($stmtObj->fetchColumn() == 0);
            $hash = $buildHash($proposal['id'], 'documento_funcional', $signer, $proposal['version']);
            if ($isFirst) {
                $pdo->prepare("INSERT INTO aprobaciones (propuesta_id, tipo, ip_address, firmante_nombre, firmante_apellidos, firmante_email, firma_hash, version_firmada, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$proposal['id'], 'documento_funcional', $_SERVER['REMOTE_ADDR'], $signer['nombre'], $signer['apellidos'], $signer['email'], $hash, $proposal['version'], mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
            }
            $prefix = $isFirst ? "✅ <b>Documento Aprobado</b>" : "🔁 <b>Re-firma Documento</b> <i>(ya estaba aprobado)</i>";
            sendTelegramNotification(
                $prefix
                . "\nCliente: <b>" . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . "</b>"
                . "\nFirmado por: <b>" . htmlspecialchars($signer['nombre'] . ' ' . $signer['apellidos'], ENT_QUOTES, 'UTF-8') . "</b>"
                . "\nVersión: " . htmlspecialchars($proposal['version'])
                . "\nHash: <code>" . substr($hash, 0, 16) . "…</code>"
                . "\n\n<a href=\"" . htmlspecialchars($adminFeedbackUrl, ENT_QUOTES) . "\">Abrir en admin</a> · <a href=\"" . htmlspecialchars($viewUrl, ENT_QUOTES) . "\">Ver propuesta</a>"
            );
            echo json_encode(['success' => true, 'hash' => $hash, 'already' => !$isFirst]);
            exit;
        }
        if ($_POST['api_action'] === 'approve_pdf') {
            $signer = $readSigner();
            if (!$signer['valid']) { echo json_encode(['success' => false, 'error' => 'Nombre y apellidos son obligatorios para firmar la aprobación.']); exit; }
            $stmtObj = $pdo->prepare("SELECT COUNT(*) FROM aprobaciones WHERE propuesta_id = ? AND tipo = 'presupuesto'");
            $stmtObj->execute([$proposal['id']]);
            $isFirst = ($stmtObj->fetchColumn() == 0);
            $hash = $buildHash($proposal['id'], 'presupuesto', $signer, $proposal['version']);
            if ($isFirst) {
                $pdo->prepare("INSERT INTO aprobaciones (propuesta_id, tipo, ip_address, firmante_nombre, firmante_apellidos, firmante_email, firma_hash, version_firmada, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$proposal['id'], 'presupuesto', $_SERVER['REMOTE_ADDR'], $signer['nombre'], $signer['apellidos'], $signer['email'], $hash, $proposal['version'], mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
            }
            $prefix = $isFirst ? "✅💰 <b>Presupuesto Aprobado</b>" : "🔁💰 <b>Re-firma Presupuesto</b> <i>(ya estaba aprobado)</i>";
            sendTelegramNotification(
                $prefix
                . "\nCliente: <b>" . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . "</b>"
                . "\nFirmado por: <b>" . htmlspecialchars($signer['nombre'] . ' ' . $signer['apellidos'], ENT_QUOTES, 'UTF-8') . "</b>"
                . "\nVersión: " . htmlspecialchars($proposal['version'])
                . "\nHash: <code>" . substr($hash, 0, 16) . "…</code>"
                . "\n\n<a href=\"" . htmlspecialchars($adminFeedbackUrl, ENT_QUOTES) . "\">Abrir en admin</a> · <a href=\"" . htmlspecialchars($viewUrl, ENT_QUOTES) . "\">Ver propuesta</a>"
            );
            echo json_encode(['success' => true, 'hash' => $hash, 'already' => !$isFirst]);
            exit;
        }
        if ($_POST['api_action'] === 'reject_pdf') {
            $comment = $_POST['comment'] ?? '';
            $pdo->prepare("INSERT INTO feedback_presupuesto (propuesta_id, tipo_accion, comentario) VALUES (?, ?, ?)")
                ->execute([$proposal['id'], 'presupuesto_rechazado_o_cambios', $comment]);
            sendTelegramNotification("❌ <b>Cambios en Presupuesto</b>\nCliente: <b>" . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . "</b>\n\n" . htmlspecialchars($comment, ENT_QUOTES, 'UTF-8'));
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['api_action'] === 'comment_doc') {
            $comment = $_POST['comment'] ?? '';
            $pdo->prepare("INSERT INTO feedback_presupuesto (propuesta_id, tipo_accion, comentario) VALUES (?, ?, ?)")
                ->execute([$proposal['id'], 'comentario_documento', $comment]);
            sendTelegramNotification("💬 <b>Comentarios del cliente</b>\nCliente: <b>" . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . "</b>\n\n" . htmlspecialchars($comment, ENT_QUOTES, 'UTF-8'));
            echo json_encode(['success' => true]);
            exit;
        }

        // --- NUEVO: Comentarios por sección ---
        if ($_POST['api_action'] === 'add_section_comment') {
            $signer = $readSigner();
            $anchor = trim($_POST['anchor'] ?? '');
            $title = trim($_POST['section_title'] ?? '');
            $texto = trim($_POST['texto'] ?? '');
            $parentId = isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null;
            if (!$signer['valid_lite']) { echo json_encode(['success' => false, 'error' => 'Identifícate antes de comentar.']); exit; }
            if ($anchor === '' || $texto === '') { echo json_encode(['success' => false, 'error' => 'Faltan datos.']); exit; }
            if (mb_strlen($texto) > 4000) { echo json_encode(['success' => false, 'error' => 'Comentario demasiado largo.']); exit; }

            $pdo->prepare("INSERT INTO comentarios_seccion (propuesta_id, section_anchor, section_title, autor_nombre, autor_apellidos, autor_email, texto, parent_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    $proposal['id'], mb_substr($anchor, 0, 200), mb_substr($title, 0, 200),
                    $signer['nombre'], $signer['apellidos'], $signer['email'],
                    $texto, $parentId,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);
            $id = (int)$pdo->lastInsertId();

            $tgHeader = $parentId ? "💬 Respuesta" : "💬 Nuevo comentario";
            $sectionUrl = $viewUrl . '#' . urlencode($anchor);
            $sectionLabel = $title !== '' ? $title : $anchor;
            // Resumen corto: 80 primeros caracteres sin romper palabras
            $resumen = mb_substr($texto, 0, 80);
            if (mb_strlen($texto) > 80) $resumen .= '…';
            sendTelegramNotification(
                $tgHeader . " · <b>" . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . "</b>"
                . "\n<i>" . htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8') . "</i>"
                . " · " . htmlspecialchars($signer['nombre'], ENT_QUOTES, 'UTF-8')
                . "\n" . htmlspecialchars($resumen, ENT_QUOTES, 'UTF-8')
                . "\n<a href=\"" . htmlspecialchars($sectionUrl, ENT_QUOTES) . "\">Ver</a> · <a href=\"" . htmlspecialchars($adminFeedbackUrl, ENT_QUOTES) . "\">Admin</a>"
            );

            echo json_encode(['success' => true, 'id' => $id, 'created_at' => date('c')]);
            exit;
        }

        if ($_POST['api_action'] === 'list_section_comments') {
            // El cliente nunca ve borradores del staff (is_draft=1)
            $stmt = $pdo->prepare("SELECT id, section_anchor, section_title, autor_nombre, autor_apellidos, texto, parent_id, resuelto, resuelto_por, resuelto_at, is_staff, created_at
                FROM comentarios_seccion
                WHERE propuesta_id = ? AND (is_draft IS NULL OR is_draft = 0)
                ORDER BY created_at ASC");
            $stmt->execute([$proposal['id']]);
            echo json_encode(['success' => true, 'comments' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // Cerrar / reabrir un hilo — SOLO el autor del comentario raíz
        if ($_POST['api_action'] === 'toggle_resolved_comment') {
            $id = (int)($_POST['id'] ?? 0);
            $signer = $readSigner();
            if (!$id || !$signer['valid_lite']) { echo json_encode(['success' => false, 'error' => 'Faltan datos.']); exit; }

            $stmt = $pdo->prepare("SELECT autor_nombre, autor_apellidos, autor_email, resuelto, parent_id, section_title, section_anchor FROM comentarios_seccion WHERE id = ? AND propuesta_id = ?");
            $stmt->execute([$id, $proposal['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['success' => false, 'error' => 'Comentario no encontrado.']); exit; }
            if ($row['parent_id']) { echo json_encode(['success' => false, 'error' => 'Solo se cierran los comentarios raíz.']); exit; }
            // Autoría: email match (más fiable que nombre cuando el login solo guarda nombre+email)
            $emailMatch = !empty($row['autor_email']) && mb_strtolower($row['autor_email']) === mb_strtolower($signer['email']);
            $nameMatch = mb_strtolower($row['autor_nombre']) === mb_strtolower($signer['nombre']) && mb_strtolower($row['autor_apellidos']) === mb_strtolower($signer['apellidos']);
            if (!$emailMatch && !$nameMatch) {
                echo json_encode(['success' => false, 'error' => 'Solo el autor puede cerrar o reabrir su comentario.']);
                exit;
            }

            $nuevo = ((int)$row['resuelto'] === 1) ? 0 : 1;
            if ($nuevo === 1) {
                $pdo->prepare("UPDATE comentarios_seccion SET resuelto = 1, resuelto_por = ?, resuelto_at = CURRENT_TIMESTAMP WHERE id = ?")
                    ->execute([trim($signer['nombre'] . ' ' . $signer['apellidos']), $id]);
                $accion = "✅ Cerrado";
            } else {
                $pdo->prepare("UPDATE comentarios_seccion SET resuelto = 0, resuelto_por = NULL, resuelto_at = NULL WHERE id = ?")
                    ->execute([$id]);
                $accion = "↩️ Reabierto";
            }
            sendTelegramNotification(
                $accion . " · <b>" . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . "</b>"
                . "\n<i>" . htmlspecialchars($row['section_title'] ?: $row['section_anchor'], ENT_QUOTES, 'UTF-8') . "</i>"
                . " · " . htmlspecialchars($signer['nombre'], ENT_QUOTES, 'UTF-8')
            );
            echo json_encode(['success' => true, 'resuelto' => $nuevo]);
            exit;
        }

        if ($_POST['api_action'] === 'edit_section_comment' || $_POST['api_action'] === 'delete_section_comment') {
            $id = (int)($_POST['id'] ?? 0);
            $signer = $readSigner();
            if (!$id || !$signer['valid_lite']) { echo json_encode(['success' => false, 'error' => 'Faltan datos para editar/eliminar.']); exit; }

            // Verificación de autoría (email match preferred; fallback nombre+apellidos)
            $stmt = $pdo->prepare("SELECT autor_nombre, autor_apellidos, autor_email, created_at FROM comentarios_seccion WHERE id = ? AND propuesta_id = ?");
            $stmt->execute([$id, $proposal['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['success' => false, 'error' => 'Comentario no encontrado.']); exit; }
            $emailMatch = !empty($row['autor_email']) && mb_strtolower($row['autor_email']) === mb_strtolower($signer['email']);
            $nameMatch = mb_strtolower($row['autor_nombre']) === mb_strtolower($signer['nombre']) && mb_strtolower($row['autor_apellidos']) === mb_strtolower($signer['apellidos']);
            if (!$emailMatch && !$nameMatch) {
                echo json_encode(['success' => false, 'error' => 'Solo el autor puede editar o eliminar este comentario.']);
                exit;
            }

            if ($_POST['api_action'] === 'edit_section_comment') {
                $texto = trim($_POST['texto'] ?? '');
                if ($texto === '' || mb_strlen($texto) > 4000) { echo json_encode(['success' => false, 'error' => 'Texto inválido.']); exit; }
                $pdo->prepare("UPDATE comentarios_seccion SET texto = ? WHERE id = ?")->execute([$texto, $id]);
                $resumenEdit = mb_substr($texto, 0, 80);
                if (mb_strlen($texto) > 80) $resumenEdit .= '…';
                sendTelegramNotification(
                    "✏️ Editado · <b>" . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . "</b>"
                    . " · " . htmlspecialchars($signer['nombre'], ENT_QUOTES, 'UTF-8')
                    . "\n" . htmlspecialchars($resumenEdit, ENT_QUOTES, 'UTF-8')
                    . "\n<a href=\"" . htmlspecialchars($adminFeedbackUrl, ENT_QUOTES) . "\">Admin</a>"
                );
                echo json_encode(['success' => true]);
                exit;
            }
            // delete
            $pdo->prepare("DELETE FROM comentarios_seccion WHERE id = ?")->execute([$id]);
            sendTelegramNotification(
                "🗑️ Eliminado · <b>" . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . "</b>"
                . " · " . htmlspecialchars($signer['nombre'], ENT_QUOTES, 'UTF-8')
            );
            echo json_encode(['success' => true]);
            exit;
        }

        // --- TAREAS DEL CLIENTE ---
        // tasks_sync: recibe la lista de tareas declaradas en el HTML del documento
        //             y hace UPSERT. Devuelve el estado actual de cada una.
        if ($_POST['api_action'] === 'tasks_sync') {
            $tasks = json_decode($_POST['tasks'] ?? '[]', true);
            if (!is_array($tasks)) $tasks = [];

            $pdo->beginTransaction();
            try {
                $upsert = $pdo->prepare("
                    INSERT INTO propuesta_tasks (propuesta_id, task_key, titulo, descripcion, asignado_a, orden)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON CONFLICT(propuesta_id, task_key) DO UPDATE SET
                        titulo = excluded.titulo,
                        descripcion = excluded.descripcion,
                        asignado_a = excluded.asignado_a,
                        orden = excluded.orden
                ");
                foreach ($tasks as $t) {
                    $key = trim($t['key'] ?? '');
                    if ($key === '') continue;
                    $upsert->execute([
                        $proposal['id'],
                        mb_substr($key, 0, 100),
                        mb_substr(trim($t['titulo'] ?? ''), 0, 300),
                        mb_substr(trim($t['descripcion'] ?? ''), 0, 4000),
                        mb_substr(trim($t['asignado_a'] ?? ''), 0, 200),
                        (int)($t['orden'] ?? 0),
                    ]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'No se pudieron sincronizar las tareas.']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT task_key, completado, completado_at, completado_por_nombre, completado_por_email, comentario_completado
                                   FROM propuesta_tasks WHERE propuesta_id = ?");
            $stmt->execute([$proposal['id']]);
            $state = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $state[$row['task_key']] = [
                    'completado' => (int)$row['completado'] === 1,
                    'completado_at' => $row['completado_at'],
                    'completado_por' => trim((string)$row['completado_por_nombre']),
                    'completado_por_email' => $row['completado_por_email'],
                    'comentario' => $row['comentario_completado'],
                ];
            }
            echo json_encode(['success' => true, 'state' => $state]);
            exit;
        }

        if ($_POST['api_action'] === 'task_complete') {
            $key = trim($_POST['task_key'] ?? '');
            $comentario = trim($_POST['comentario'] ?? '');
            $signer = $readSigner();
            if ($key === '') { echo json_encode(['success' => false, 'error' => 'Falta el identificador de la tarea.']); exit; }
            if (!$signer['valid_lite']) {
                echo json_encode(['success' => false, 'error' => 'Identifícate antes de marcar la tarea como completada.']);
                exit;
            }
            if (mb_strlen($comentario) > 4000) { echo json_encode(['success' => false, 'error' => 'Comentario demasiado largo.']); exit; }

            $stmt = $pdo->prepare("SELECT id, titulo, asignado_a, completado FROM propuesta_tasks WHERE propuesta_id = ? AND task_key = ?");
            $stmt->execute([$proposal['id'], $key]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$task) { echo json_encode(['success' => false, 'error' => 'Tarea no encontrada.']); exit; }
            if ((int)$task['completado'] === 1) {
                echo json_encode(['success' => false, 'error' => 'Esta tarea ya estaba marcada como completada.']);
                exit;
            }

            $nombreCompleto = trim($signer['nombre'] . ' ' . $signer['apellidos']);
            $pdo->prepare("UPDATE propuesta_tasks
                           SET completado = 1, completado_at = CURRENT_TIMESTAMP,
                               completado_por_nombre = ?, completado_por_email = ?, comentario_completado = ?
                           WHERE id = ?")
                ->execute([$nombreCompleto, $signer['email'], $comentario !== '' ? $comentario : null, $task['id']]);

            $resumenComentario = $comentario !== '' ? "\n<i>" . htmlspecialchars(mb_substr($comentario, 0, 240), ENT_QUOTES, 'UTF-8') . (mb_strlen($comentario) > 240 ? '…' : '') . "</i>" : '';
            sendTelegramNotification(
                "✅ <b>Tarea completada</b> · <b>" . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . "</b>"
                . "\n<b>" . htmlspecialchars($task['titulo'], ENT_QUOTES, 'UTF-8') . "</b>"
                . "\nPor: " . htmlspecialchars($nombreCompleto, ENT_QUOTES, 'UTF-8') . " · " . htmlspecialchars($signer['email'], ENT_QUOTES, 'UTF-8')
                . $resumenComentario
                . "\n<a href=\"" . htmlspecialchars($viewUrl, ENT_QUOTES) . "#tareas-cliente\">Ver propuesta</a>"
            );

            echo json_encode([
                'success' => true,
                'completado_at' => date('c'),
                'completado_por' => $nombreCompleto,
                'completado_por_email' => $signer['email'],
                'comentario' => $comentario,
            ]);
            exit;
        }

        // --- RESPUESTAS DEL CLIENTE (cajas de texto con botón Guardar) ---
        // respuestas_sync: registra las preguntas declaradas en el HTML y devuelve
        //                  las respuestas ya guardadas. Auto-crea la tabla si no existe
        //                  (evita tener que correr la migración a mano en producción).
        if (in_array($_POST['api_action'], ['respuestas_sync', 'respuesta_save', 'respuestas_submit'], true)) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS propuesta_respuestas (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    propuesta_id INTEGER NOT NULL,
                    respuesta_key TEXT NOT NULL,
                    pregunta TEXT,
                    respuesta_texto TEXT,
                    autor_nombre TEXT,
                    autor_email TEXT,
                    orden INTEGER DEFAULT 0,
                    updated_at DATETIME,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(propuesta_id, respuesta_key)
                )
            ");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_respuestas_propuesta ON propuesta_respuestas(propuesta_id)");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS propuesta_respuestas_envios (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    propuesta_id INTEGER NOT NULL,
                    grupo TEXT NOT NULL DEFAULT '',
                    enviado_at DATETIME,
                    enviado_por_nombre TEXT,
                    enviado_por_email TEXT,
                    total INTEGER DEFAULT 0,
                    UNIQUE(propuesta_id, grupo)
                )
            ");
        }

        if ($_POST['api_action'] === 'respuestas_sync') {
            $items = json_decode($_POST['respuestas'] ?? '[]', true);
            if (!is_array($items)) $items = [];

            $pdo->beginTransaction();
            try {
                $upsert = $pdo->prepare("
                    INSERT INTO propuesta_respuestas (propuesta_id, respuesta_key, pregunta, orden)
                    VALUES (?, ?, ?, ?)
                    ON CONFLICT(propuesta_id, respuesta_key) DO UPDATE SET
                        pregunta = excluded.pregunta,
                        orden = excluded.orden
                ");
                foreach ($items as $idx => $it) {
                    $key = trim($it['key'] ?? '');
                    if ($key === '') continue;
                    $upsert->execute([
                        $proposal['id'],
                        mb_substr($key, 0, 100),
                        mb_substr(trim($it['pregunta'] ?? ''), 0, 500),
                        (int)($it['orden'] ?? $idx),
                    ]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'No se pudieron sincronizar las respuestas.']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT respuesta_key, respuesta_texto, autor_nombre, autor_email, updated_at
                                   FROM propuesta_respuestas WHERE propuesta_id = ?");
            $stmt->execute([$proposal['id']]);
            $state = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $state[$row['respuesta_key']] = [
                    'texto' => (string)$row['respuesta_texto'],
                    'autor' => trim((string)$row['autor_nombre']),
                    'autor_email' => $row['autor_email'],
                    'updated_at' => $row['updated_at'],
                ];
            }
            $envStmt = $pdo->prepare("SELECT grupo, enviado_at, enviado_por_nombre FROM propuesta_respuestas_envios WHERE propuesta_id = ?");
            $envStmt->execute([$proposal['id']]);
            $envios = [];
            foreach ($envStmt->fetchAll(PDO::FETCH_ASSOC) as $er) {
                $envios[$er['grupo']] = ['enviado_at' => $er['enviado_at'], 'autor' => trim((string)$er['enviado_por_nombre'])];
            }
            echo json_encode(['success' => true, 'state' => $state, 'envios' => $envios]);
            exit;
        }

        if ($_POST['api_action'] === 'respuesta_save') {
            $key = trim($_POST['respuesta_key'] ?? '');
            $texto = trim($_POST['texto'] ?? '');
            $signer = $readSigner();
            if ($key === '') { echo json_encode(['success' => false, 'error' => 'Falta el identificador de la pregunta.']); exit; }
            if (!$signer['valid_lite']) {
                echo json_encode(['success' => false, 'error' => 'Identifícate antes de guardar tu respuesta.']);
                exit;
            }
            if (mb_strlen($texto) > 8000) { echo json_encode(['success' => false, 'error' => 'Respuesta demasiado larga (máx. 8000 caracteres).']); exit; }

            $stmt = $pdo->prepare("SELECT id, pregunta FROM propuesta_respuestas WHERE propuesta_id = ? AND respuesta_key = ?");
            $stmt->execute([$proposal['id'], $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $nombreCompleto = trim($signer['nombre'] . ' ' . $signer['apellidos']);

            if ($row) {
                $pdo->prepare("UPDATE propuesta_respuestas
                               SET respuesta_texto = ?, autor_nombre = ?, autor_email = ?, updated_at = CURRENT_TIMESTAMP
                               WHERE id = ?")
                    ->execute([$texto, $nombreCompleto, $signer['email'], $row['id']]);
                $pregunta = (string)$row['pregunta'];
            } else {
                $pdo->prepare("INSERT INTO propuesta_respuestas (propuesta_id, respuesta_key, pregunta, respuesta_texto, autor_nombre, autor_email, updated_at)
                               VALUES (?, ?, '', ?, ?, ?, CURRENT_TIMESTAMP)")
                    ->execute([$proposal['id'], mb_substr($key, 0, 100), $texto, $nombreCompleto, $signer['email']]);
                $pregunta = '';
            }

            $resumen = $texto !== ''
                ? "\n<i>" . htmlspecialchars(mb_substr($texto, 0, 280), ENT_QUOTES, 'UTF-8') . (mb_strlen($texto) > 280 ? '…' : '') . "</i>"
                : "\n<i>(respuesta vaciada)</i>";
            sendTelegramNotification(
                "✍️ <b>Respuesta del cliente</b> · <b>" . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . "</b>"
                . ($pregunta !== '' ? "\n<b>" . htmlspecialchars($pregunta, ENT_QUOTES, 'UTF-8') . "</b>" : "\n<code>" . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . "</code>")
                . "\nPor: " . htmlspecialchars($nombreCompleto, ENT_QUOTES, 'UTF-8') . " · " . htmlspecialchars($signer['email'], ENT_QUOTES, 'UTF-8')
                . $resumen
                . "\n<a href=\"" . htmlspecialchars($viewUrl, ENT_QUOTES) . "\">Ver propuesta</a>"
            );

            echo json_encode([
                'success' => true,
                'texto' => $texto,
                'autor' => $nombreCompleto,
                'autor_email' => $signer['email'],
                'updated_at' => date('c'),
            ]);
            exit;
        }

        if ($_POST['api_action'] === 'respuestas_submit') {
            $grupo = trim($_POST['grupo'] ?? '');
            $keys = json_decode($_POST['keys'] ?? '[]', true);
            if (!is_array($keys)) $keys = [];
            $keys = array_values(array_filter(array_map(fn($k) => trim((string)$k), $keys), fn($k) => $k !== ''));
            $signer = $readSigner();
            if (!$signer['valid_lite']) { echo json_encode(['success' => false, 'error' => 'Identifícate antes de enviar.']); exit; }
            if (!$keys) { echo json_encode(['success' => false, 'error' => 'No hay preguntas que enviar.']); exit; }

            $place = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $pdo->prepare("SELECT respuesta_key, pregunta, respuesta_texto FROM propuesta_respuestas WHERE propuesta_id = ? AND respuesta_key IN ($place)");
            $stmt->execute(array_merge([$proposal['id']], $keys));
            $byKey = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $byKey[$r['respuesta_key']] = $r;

            $faltan = [];
            foreach ($keys as $k) {
                if (!isset($byKey[$k]) || trim((string)$byKey[$k]['respuesta_texto']) === '') $faltan[] = $k;
            }
            if ($faltan) {
                echo json_encode(['success' => false, 'error' => 'Faltan respuestas por contestar antes de enviar.', 'faltan' => $faltan]);
                exit;
            }

            $nombreCompleto = trim($signer['nombre'] . ' ' . $signer['apellidos']);
            $pdo->prepare("INSERT INTO propuesta_respuestas_envios (propuesta_id, grupo, enviado_at, enviado_por_nombre, enviado_por_email, total)
                           VALUES (?, ?, CURRENT_TIMESTAMP, ?, ?, ?)
                           ON CONFLICT(propuesta_id, grupo) DO UPDATE SET
                               enviado_at = CURRENT_TIMESTAMP,
                               enviado_por_nombre = excluded.enviado_por_nombre,
                               enviado_por_email = excluded.enviado_por_email,
                               total = excluded.total")
                ->execute([$proposal['id'], $grupo, $nombreCompleto, $signer['email'], count($keys)]);

            $msg = "📨 <b>Respuestas enviadas</b> · <b>" . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . "</b>"
                 . "\nPor: " . htmlspecialchars($nombreCompleto, ENT_QUOTES, 'UTF-8') . " · " . htmlspecialchars($signer['email'], ENT_QUOTES, 'UTF-8')
                 . "\n" . count($keys) . " respuestas completas:\n";
            $n = 0;
            foreach ($keys as $k) {
                $n++;
                $p = trim((string)$byKey[$k]['pregunta']);
                $t = trim((string)$byKey[$k]['respuesta_texto']);
                $msg .= "\n<b>" . $n . ". " . htmlspecialchars($p !== '' ? $p : $k, ENT_QUOTES, 'UTF-8') . "</b>\n"
                      . htmlspecialchars(mb_substr($t, 0, 200), ENT_QUOTES, 'UTF-8') . (mb_strlen($t) > 200 ? '…' : '') . "\n";
            }
            $msg .= "\n<a href=\"" . htmlspecialchars($viewUrl, ENT_QUOTES) . "\">Ver propuesta</a>";
            if (mb_strlen($msg) > 3900) $msg = mb_substr($msg, 0, 3900) . "…";
            sendTelegramNotification($msg);

            echo json_encode(['success' => true, 'enviado_at' => date('c'), 'autor' => $nombreCompleto]);
            exit;
        }

        echo json_encode(['success' => false, 'error' => 'Acción no reconocida']);
        exit;
    }

    // Clean full HTML wrapping if present
    $content = preg_replace('/<!DOCTYPE[^>]*>/is', '', $content);
    $content = preg_replace('/<html[^>]*>/is', '', $content);
    $content = preg_replace('/<\/html>/is', '', $content);
    $content = preg_replace('/<head[^>]*>.*?<\/head>/is', '', $content);
    $content = preg_replace('/<body[^>]*>/is', '', $content);
    $content = preg_replace('/<\/body>/is', '', $content);
    $content = preg_replace('/<aside[^>]*>.*?<\/aside>/is', '', $content);
    $content = preg_replace('/<nav[^>]*>.*?<\/nav>/is', '', $content);
    $content = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $content);
        $content = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $content);
        $content = preg_replace('/<main[^>]*>/is', '', $content);
        $content = preg_replace('/<\/main>/is', '', $content);
        $content = preg_replace('/<header[^>]*>.*?<\/header>/is', '', $content);
        $content = preg_replace('/<footer[^>]*>.*?<\/footer>/is', '', $content);
        $content = preg_replace('/<div[^>]*class=["\']content-wrapper["\'][^>]*>/is', '<div>', $content);
        $content = preg_replace('/<div[^>]*id=["\']content-wrapper["\'][^>]*>/is', '<div>', $content);
        $content = preg_replace('/<div[^>]*class=["\']app-container["\'][^>]*>/is', '<div>', $content);

        // Auto-wrap tables with .table-scroll (skips tables already wrapped)
        $content = preg_replace_callback(
            '/(<table\b[^>]*>.*?<\/table>)/is',
            function ($m) {
                return '<div class="table-scroll">' . $m[1] . '</div>';
            },
            $content
        );

        $proposal['html_content'] = trim($content);

        $isDocApproved = false;
        $isPdfApproved = false;
        $firmas = [];
        $stmtObj = $pdo -> prepare("SELECT tipo, firmante_nombre, firmante_apellidos, firmante_email, firma_hash, version_firmada, aprobado_at FROM aprobaciones WHERE propuesta_id = ? ORDER BY aprobado_at ASC");
        $stmtObj -> execute([$proposal['id']]);
        while ($row = $stmtObj -> fetch(PDO:: FETCH_ASSOC)) {
            if ($row['tipo'] === 'documento_funcional')
                $isDocApproved = true;
            if ($row['tipo'] === 'presupuesto')
                $isPdfApproved = true;
            $firmas[] = $row;
        }
        $hasPdf = !empty($proposal['presupuesto_pdf']);

        // Presupuesto vinculado a Holded (tiene prioridad sobre el PDF legacy)
        $holdedRow = null;
        $holdedDoc = null;
        $hasHolded = false;
        try {
            $stmtH = $pdo->prepare("SELECT holded_id, holded_doc_number, holded_json, synced_at FROM presupuestos_holded WHERE propuesta_id = ?");
            $stmtH->execute([$proposal['id']]);
            $holdedRow = $stmtH->fetch(PDO::FETCH_ASSOC);
            if ($holdedRow) {
                $holdedDoc = json_decode($holdedRow['holded_json'], true);
                $hasHolded = is_array($holdedDoc) && !empty($holdedDoc);
            }
        } catch (Throwable $e) { /* tabla puede no existir aún en entornos viejos */ }

        // Load Team
        $equipo_ids_json = $proposal['equipo_ids'] ?? '[]';
        $equipo_ids = json_decode($equipo_ids_json, true);
        if (!is_array($equipo_ids))
            $equipo_ids = [];

        if (empty($equipo_ids)) {
            $team = [];
        }
        else {
            $placeholders = implode(',', array_fill(0, count($equipo_ids), '?'));
            $stmtTeam = $pdo -> prepare("SELECT * FROM equipo WHERE id IN ($placeholders) ORDER BY orden ASC, created_at DESC");
            $stmtTeam -> execute($equipo_ids);
            $team = $stmtTeam -> fetchAll(PDO:: FETCH_ASSOC);
        }
        renderWrappedContent($proposal, $slug, $isDocApproved, $isPdfApproved, $hasPdf, $team, $base_path, $hasHolded, $holdedDoc, $firmas, $isProviderMode, $__provider, $isAdminMode, $visitorIdentity);
        exit;
}

        // Función de error
        function showError($title, $message) {
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>
        <?php echo $title; ?>
    </title>
    <style>
        body {
            background: #0E0E0E;
            color: #FFF;
            font-family: sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            text-align: center;
            padding: 20px;
        }
    </style>
</head>

<body>
    <div>
        <h1>
            <?php echo $title; ?>
        </h1>
        <p>
            <?php echo $message; ?>
        </p><a href="/" style="color:#5DFFBF;">Volver al inicio</a>
    </div>
</body>

</html>
<?php
    exit;
}

        renderPinGate($proposal, $login_errors, $login_prefill, $base_path, $slug);
        exit;

        function renderPinGate($proposal, $login_errors, $login_prefill, $base_path, $slug) {
    $storageKey = 'tp_visitor_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $slug);
    $nomPref = htmlspecialchars($login_prefill['nombre'] ?? '', ENT_QUOTES);
    $emlPref = htmlspecialchars($login_prefill['email'] ?? '', ENT_QUOTES);
    $clientNameSafe = htmlspecialchars($proposal['client_name']);
?>
<!DOCTYPE html>
<html lang="es" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $clientNameSafe ?> | Acceso</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background-color: #050505;
            color: #E0E0E0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .gate-container {
            width: 100%;
            max-width: 440px;
            padding: 2.4rem 2.5rem 2rem;
            background: #0A0A0A;
            border-radius: 24px;
            border: 1px solid #1A1A1A;
        }
        .gate-logo { text-align: center; margin-bottom: 1.6rem; }
        .gate-logo img { height: 32px; }
        .gate-label {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 10px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.35em;
            color: rgba(255,255,255,0.4);
            text-align: center;
            margin-bottom: .35rem;
        }
        .gate-client {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.25rem; font-weight: 800;
            color: #f5f5f5;
            text-align: center;
            margin-bottom: 1.9rem;
        }
        .gate-field { margin-bottom: 1rem; text-align: left; }
        .gate-field label {
            display: block;
            font-size: 11px; font-weight: 600;
            color: #8a8a8a;
            text-transform: uppercase; letter-spacing: .08em;
            margin-bottom: .35rem;
        }
        .gate-field input {
            width: 100%;
            background: #141414;
            border: 1px solid #1f1f1f;
            border-radius: 10px;
            color: #f5f5f5;
            font: inherit;
            font-size: 15px;
            padding: .8rem 1rem;
            outline: none;
            transition: border-color .15s, background .15s;
        }
        .gate-field input:focus {
            border-color: #5DFFBF;
            background: #0f1511;
        }
        .gate-field.has-error input { border-color: #ef4444; background: rgba(239,68,68,.05); }
        .gate-field .err {
            font-size: 11px; color: #ef4444; margin-top: .35rem;
        }
        .pin-input {
            letter-spacing: .3em;
            text-align: center;
            font-family: 'JetBrains Mono', monospace;
            font-size: 1.6rem !important;
            color: #5DFFBF !important;
        }
        .btn-unlock {
            width: 100%;
            background: #5DFFBF;
            color: #050505;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-weight: 800;
            padding: 1.05rem;
            border: none; border-radius: 12px;
            font-size: 13px;
            text-transform: uppercase; letter-spacing: 0.08em;
            cursor: pointer;
            margin-top: .6rem;
            transition: background .2s, transform .1s;
        }
        .btn-unlock:hover { background: #49e6a8; }
        .btn-unlock:active { transform: translateY(1px); }
        .gate-legal {
            font-size: 11px;
            line-height: 1.5;
            color: #666;
            text-align: center;
            margin-top: 1.3rem;
        }
        .gate-legal svg { display: inline; vertical-align: -2px; margin-right: 3px; }
        .not-me {
            display: block;
            font-size: 11px;
            color: #8a8a8a;
            text-decoration: underline;
            text-align: center;
            margin-top: .9rem;
            cursor: pointer;
            background: none;
            border: none;
            width: 100%;
        }
        .not-me:hover { color: #f5f5f5; }
    </style>
</head>
<body>
    <div class="gate-container">
        <div class="gate-logo"><img src="/master/brand/logo-dark.svg" alt="Tres Puntos"></div>
        <p class="gate-label">Acceso a propuesta</p>
        <p class="gate-client"><?= $clientNameSafe ?></p>

        <form method="POST" autocomplete="on" novalidate>
            <div class="gate-field<?= isset($login_errors['nombre']) ? ' has-error' : '' ?>">
                <label for="v-nombre">Nombre</label>
                <input id="v-nombre" type="text" name="visitor_nombre" value="<?= $nomPref ?>"
                       autocomplete="name" maxlength="120" required
                       placeholder="Tu nombre completo">
                <?php if (isset($login_errors['nombre'])): ?>
                    <div class="err"><?= htmlspecialchars($login_errors['nombre']) ?></div>
                <?php endif; ?>
            </div>

            <div class="gate-field<?= isset($login_errors['email']) ? ' has-error' : '' ?>">
                <label for="v-email">Email</label>
                <input id="v-email" type="email" name="visitor_email" value="<?= $emlPref ?>"
                       autocomplete="email" maxlength="180" required inputmode="email"
                       placeholder="tu@empresa.com">
                <?php if (isset($login_errors['email'])): ?>
                    <div class="err"><?= htmlspecialchars($login_errors['email']) ?></div>
                <?php endif; ?>
            </div>

            <div class="gate-field<?= isset($login_errors['pin']) ? ' has-error' : '' ?>">
                <label for="v-pin">PIN de acceso</label>
                <input id="v-pin" type="password" name="pin" maxlength="10" required
                       class="pin-input" autocomplete="off" placeholder="••••">
                <?php if (isset($login_errors['pin'])): ?>
                    <div class="err"><?= htmlspecialchars($login_errors['pin']) ?></div>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn-unlock">Acceder al proyecto</button>

            <button type="button" class="not-me" id="not-me" style="display:none;" onclick="tpClearIdentity()">
                ¿No eres tú? · Cambiar datos
            </button>
        </form>

        <p class="gate-legal">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Tu email solo identifica quién comenta o firma. No enviamos marketing.
        </p>
    </div>

    <script>
    (function() {
        const KEY = <?= json_encode($storageKey) ?>;
        const nameEl = document.getElementById('v-nombre');
        const emailEl = document.getElementById('v-email');
        const pinEl = document.getElementById('v-pin');
        const notMeBtn = document.getElementById('not-me');

        // Auto-fill desde localStorage solo si los inputs vienen vacíos (ie, no son valores del POST fallido)
        if (!nameEl.value && !emailEl.value) {
            try {
                const saved = JSON.parse(localStorage.getItem(KEY) || 'null');
                if (saved && saved.nombre && saved.email) {
                    nameEl.value = saved.nombre;
                    emailEl.value = saved.email;
                    notMeBtn.style.display = 'inline-block';
                    // Cursor directo al PIN — lo demás ya está puesto
                    setTimeout(() => pinEl.focus(), 60);
                    return;
                }
            } catch (e) {}
        }
        // No había localStorage → cursor al primer campo vacío
        if (!nameEl.value) nameEl.focus();
        else if (!emailEl.value) emailEl.focus();
        else pinEl.focus();

        // Guardar al enviar formulario (si todo OK, se re-aplicará; si falla, quedan almacenados para re-prefill)
        document.querySelector('form').addEventListener('submit', function() {
            if (nameEl.value && emailEl.value) {
                try {
                    localStorage.setItem(KEY, JSON.stringify({
                        nombre: nameEl.value.trim(),
                        email: emailEl.value.trim().toLowerCase()
                    }));
                } catch (e) {}
            }
        });

        window.tpClearIdentity = function() {
            try { localStorage.removeItem(KEY); } catch (e) {}
            nameEl.value = '';
            emailEl.value = '';
            notMeBtn.style.display = 'none';
            nameEl.focus();
        };
    })();
    </script>
</body>
</html>
<?php
}

                            function renderWrappedContent($proposal, $slug, $isDocApproved = false, $isPdfApproved = false, $hasPdf = false, $team = [], $base_path = '', $hasHolded = false, $holdedDoc = null, $firmas = [], $isProviderMode = false, $__provider = null, $isAdminMode = false, $visitorIdentity = null)
                            {
?>
<!DOCTYPE html>
                            <html lang="es" class="scroll-smooth">

                                <head>
                                    <meta charset="utf-8">
                                        <meta name="viewport" content="width=device-width, initial-scale=1.0">
                                            <title>
                                                <?php echo htmlspecialchars($proposal['client_name']); ?> | Propuesta Tres Puntos
                                            </title>
                                            <link
                                                href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Plus+Jakarta+Sans:wght@700;800&family=JetBrains+Mono:wght@400;500&display=swap"
                                                rel="stylesheet">
                                                <link rel="stylesheet" href="/master/doc-library.css?v=<?php echo @filemtime(__DIR__.'/master/doc-library.css'); ?>">
                                                <script>
                                                    // Theme init antes del render para evitar FOUC
                                                    (function() {
                                                        try {
                                                            var stored = localStorage.getItem('tp-theme');
                                                            var sysLight = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches;
                                                            var theme = stored || (sysLight ? 'light' : 'dark');
                                                            document.documentElement.setAttribute('data-theme', theme);
                                                        } catch(e) {
                                                            document.documentElement.setAttribute('data-theme', 'dark');
                                                        }
                                                    })();
                                                </script>
                                                <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root {
            --tp-primary: #5DFFBF;
            --tp-primary-rgb: 93, 255, 191;
            --mint: #5DFFBF;
            --mint-hover: #49E6A8;
            --mint-rgb: 93, 255, 191;
            --bg-base: #0E0E0E;
            --bg-surface: #141414;
            --bg-subtle: #191919;
            --bg-muted: #1F1F1F;
            --bg-nav-hover: #1A1A1A;
            --bg-nav-active: #2A2A2A;
            --text-primary: #F5F5F5;
            --text-secondary: #B3B3B3;
            --text-muted: #8A8A8A;
            --border-base: #1F1F1F;
            --border-subtle: #1A1A1A;
            --border-strong: #2A2A2A;
            --font-heading: 'Plus Jakarta Sans', sans-serif;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --overlay-scrim: rgba(0, 0, 0, 0.75);
        }

        [data-theme="light"] {
            --tp-primary: #0FA36C;
            --tp-primary-rgb: 15, 163, 108;
            --mint: #0FA36C;
            --mint-hover: #0D8F5E;
            --mint-rgb: 15, 163, 108;
            --bg-base: #F7F6F3;
            --bg-surface: #FFFFFF;
            --bg-subtle: #F0EFEB;
            --bg-muted: #E8E6E0;
            --bg-nav-hover: #F0EFEB;
            --bg-nav-active: #E8E6E0;
            --text-primary: #141414;
            --text-secondary: #4A4A4A;
            --text-muted: #6E6E6E;
            --border-base: #E4E2DC;
            --border-subtle: #ECEAE4;
            --border-strong: #D0CEC6;
            --overlay-scrim: rgba(20, 20, 20, 0.4);
        }

        html {
            color-scheme: dark;
        }
        [data-theme="light"] {
            color-scheme: light;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            background: var(--bg-base);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        .app-container {
            display: flex;
            min-height: 100vh;
        }

        aside {
            width: 320px;
            height: 100vh;
            background: var(--bg-surface);
            border-right: 1px solid var(--border-base);
            position: fixed;
            padding: 2.5rem 1.5rem;
            display: flex;
            flex-direction: column;
            z-index: 100;
        }

        .sidebar-brand {
            margin-bottom: 3rem;
            display: flex;
            align-items: center;
            gap: .9rem;
        }
        .sidebar-brand img { display: block; }

        /* Logos theme-aware: dark sobre fondo dark, light sobre fondo claro
           Doble clase para vencer specificity de .sidebar-brand img y .mobile-logo */
        .tp-logo.tp-logo--light { display: none !important; }
        .tp-logo.tp-logo--dark  { display: block !important; }
        [data-theme="light"] .tp-logo.tp-logo--dark  { display: none !important; }
        [data-theme="light"] .tp-logo.tp-logo--light { display: block !important; }

        /* Columna con pill "Beta" + subtítulo "by TresPuntos Lab" */
        .beta-stack {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: .55rem;
            line-height: 1.1;
        }
        .beta-badge {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .2rem .6rem;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .14em;
            text-transform: uppercase;
            color: var(--tp-primary);
            background: rgba(var(--tp-primary-rgb), .08);
            border: 1px solid rgba(var(--tp-primary-rgb), .3);
            border-radius: 999px;
            font-family: var(--font-heading, inherit);
            white-space: nowrap;
        }
        .beta-badge::before {
            content: "";
            width: 5px;
            height: 5px;
            border-radius: 999px;
            background: var(--tp-primary);
            box-shadow: 0 0 6px rgba(var(--tp-primary-rgb), .8);
        }
        .beta-badge-sub {
            color: var(--text-muted);
            font-weight: 500;
            text-transform: none;
            letter-spacing: .02em;
            font-size: 10px;
            white-space: nowrap;
        }
        .mobile-header .beta-badge {
            margin-left: .6rem;
            font-size: 8px;
            padding: .14rem .45rem;
        }

        .sidebar-nav-container {
            flex: 1;
            overflow-y: auto;
            scrollbar-width: none;
        }

        .sidebar-nav-container::-webkit-scrollbar {
            display: none;
        }

        #sidebar-nav {
            list-style: none;
        }

        .nav-item {
            margin-bottom: 0.25rem;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            border-radius: 8px;
            transition: all 0.2s;
        }

        .nav-link:hover {
            background: var(--bg-nav-hover);
            color: var(--text-primary);
        }

        .nav-link.active {
            background: var(--bg-nav-active);
            color: var(--text-primary);
            font-weight: 600;
        }

        .nav-link-cta {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.85rem 1rem;
            color: var(--tp-primary);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.2s;
            background: rgba(var(--tp-primary-rgb), 0.08);
            margin-top: 1rem;
            margin-bottom: 1rem;
            text-align: center;
            border: 1px solid rgba(var(--tp-primary-rgb), 0.2);
        }

        .nav-link-cta:hover {
            background: rgba(var(--tp-primary-rgb), 0.15);
            border-color: rgba(var(--tp-primary-rgb), 0.4);
        }

        .nav-link-cta.active {
            background: rgba(var(--tp-primary-rgb), 0.2);
            border-color: var(--tp-primary);
            font-weight: 700;
            box-shadow: 0 0 15px rgba(var(--tp-primary-rgb), 0.1);
        }

        /* Modals */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(6px);
            z-index: 9000;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal-box {
            background: var(--bg-surface);
            border: 1px solid #2A2A2A;
            border-radius: 20px;
            padding: 3rem;
            width: 100%;
            max-width: 560px;
            position: relative;
            animation: modalIn 0.25s ease;
        }

        @keyframes modalIn {
            from {
                opacity: 0;
                transform: translateY(20px) scale(0.97);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-close {
            position: absolute;
            top: 1.25rem;
            right: 1.25rem;
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 8px;
            transition: color 0.2s;
        }

        .modal-close:hover {
            color: var(--text-primary);
        }

        .modal-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
        }

        .modal-icon.green {
            background: rgba(93, 255, 191, 0.1);
            color: var(--tp-primary);
        }

        .modal-icon.blue {
            background: rgba(100, 150, 255, 0.1);
            color: #7B96FF;
        }

        .modal-box h3 {
            font-family: var(--font-heading);
            font-size: 1.6rem;
            color: var(--text-primary);
            margin-bottom: 0.75rem;
        }

        .modal-box p {
            color: #888;
            font-size: 1rem;
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }

        .modal-textarea {
            width: 100%;
            background: var(--bg-base);
            border: 1px solid #2A2A2A;
            border-radius: 12px;
            padding: 1.25rem;
            color: #DDD;
            font-family: 'Inter', sans-serif;
            font-size: 1rem;
            line-height: 1.6;
            resize: vertical;
            min-height: 180px;
            outline: none;
            transition: border-color 0.2s;
            margin-bottom: 1.5rem;
        }

        .modal-textarea:focus {
            border-color: var(--tp-primary);
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        .btn-modal-primary {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.875rem 1.75rem;
            background: var(--tp-primary);
            color: #000;
            font-family: var(--font-heading);
            font-weight: 700;
            border: none;
            border-radius: 10px;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-modal-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(93, 255, 191, 0.2);
        }

        .btn-modal-secondary {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.875rem 1.5rem;
            background: transparent;
            color: #888;
            font-family: var(--font-heading);
            font-weight: 600;
            border: 1px solid #2A2A2A;
            border-radius: 10px;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-modal-secondary:hover {
            color: var(--text-primary);
            border-color: #444;
        }

        .modal-success {
            display: none;
            text-align: center;
            padding: 1rem 0;
        }

        .modal-success .success-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .modal-success h4 {
            font-family: var(--font-heading);
            font-size: 1.3rem;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .modal-success p {
            color: #888;
            font-size: 0.95rem;
        }

        /* CTA Block */
        .cta-block {
            margin-top: 8rem;
            padding: 4rem;
            background: linear-gradient(135deg, #141414 0%, #0E0E0E 100%);
            border: 1px solid #2A2A2A;
            border-radius: 24px;
            text-align: center;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .cta-block::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--tp-primary), transparent);
        }

        .cta-block h2 {
            font-family: var(--font-heading);
            font-size: 2.5rem;
            color: var(--text-primary);
            margin-bottom: 1rem;
            margin-top: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            width: 100%;
            text-align: center;
        }

        .cta-block h2::before {
            display: none;
        }

        .cta-block p {
            color: #888;
            font-size: 1.1rem;
            max-width: 600px;
            margin: 0 auto 2.5rem;
            text-align: center;
        }

        .cta-buttons {
            display: flex;
            justify-content: center;
            gap: 1rem;
            flex-wrap: wrap;
            width: 100%;
        }

        .btn-cta-primary {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 2rem;
            background: var(--tp-primary);
            color: #000;
            font-family: var(--font-heading);
            font-weight: 800;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.25s;
        }

        .btn-cta-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(93, 255, 191, 0.25);
        }

        .btn-cta-secondary {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 2rem;
            background: transparent;
            color: #CCC;
            font-family: var(--font-heading);
            font-weight: 700;
            border: 1px solid #2A2A2A;
            border-radius: 12px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.25s;
        }

        .btn-cta-secondary:hover {
            background: var(--bg-nav-hover);
            color: var(--text-primary);
            border-color: #444;
        }

        main {
            margin-left: 320px;
            flex: 1;
            min-width: 0;
            padding: 5rem 3rem;
        }

        .content-wrapper {
            max-width: 1080px;
            margin: 0 auto;
            min-width: 0;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
        }
        .content-wrapper input,
        .content-wrapper textarea {
            -webkit-user-select: text;
            user-select: text;
        }

        @media (min-width: 1600px) {
            main { padding: 5rem 4rem; }
            .content-wrapper { max-width: 1160px; }
        }

        h1 {
            font-family: var(--font-heading);
            font-size: 2.6rem;
            font-weight: 800;
            color: var(--text-primary);
            letter-spacing: -0.03em;
            margin-bottom: 1rem;
        }

        .doc-meta {
            font-size: 1rem;
            color: var(--text-muted);
            margin-bottom: 2.5rem;
        }

        /* Tabs sticky: documento · presupuesto · firmas */
        .doc-tabs {
            position: sticky;
            top: 0;
            z-index: 50;
            display: flex;
            gap: .35rem;
            padding: .6rem;
            margin: 0 -0.6rem 3rem;
            background: color-mix(in srgb, var(--bg-base) 92%, transparent);
            backdrop-filter: saturate(140%) blur(10px);
            -webkit-backdrop-filter: saturate(140%) blur(10px);
            border: 1px solid var(--border-base);
            border-radius: 14px;
            overflow-x: auto;
            scrollbar-width: none;
        }
        .doc-tabs::-webkit-scrollbar { display: none; }

        .doc-tab {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .6rem 1rem;
            border: 0;
            background: transparent;
            color: var(--text-secondary);
            font-family: var(--font-heading);
            font-size: .88rem;
            font-weight: 600;
            letter-spacing: .01em;
            border-radius: 10px;
            cursor: pointer;
            white-space: nowrap;
            transition: background .18s ease, color .18s ease, transform .18s ease;
        }
        .doc-tab i { width: 16px; height: 16px; flex: 0 0 auto; }
        .doc-tab:hover { background: var(--bg-nav-hover); color: var(--text-primary); }
        .doc-tab.is-active {
            background: var(--tp-primary);
            color: #0e0e0e;
        }
        [data-theme="light"] .doc-tab.is-active { color: #ffffff; }
        .doc-tab.is-active:hover { background: var(--tp-primary); }
        .doc-tab__count {
            display: inline-flex;
            min-width: 20px;
            padding: 0 .35rem;
            height: 20px;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: rgba(255, 255, 255, .15);
            font-size: .7rem;
            font-weight: 700;
            color: inherit;
        }
        .doc-tab:not(.is-active) .doc-tab__count {
            background: var(--bg-nav-hover);
            color: var(--text-muted);
        }

        /* Oculta el sidebar-nav cuando estamos fuera del documento (deja el brand/theme-toggle) */
        body.is-tab-presupuesto .sidebar-nav-container,
        body.is-tab-firmas .sidebar-nav-container { display: none; }

        @media (max-width: 768px) {
            .doc-tabs { margin: 0 0 2rem; border-radius: 12px; }
            .doc-tab { padding: .55rem .8rem; font-size: .82rem; }
        }

        /* ========================================================
           tp-signatures — registro de firmas
           ======================================================== */
        .tp-signatures { max-width: 900px; margin: 0 auto; }
        .tp-signatures__head { margin-bottom: 2rem; }
        .tp-signatures__head h2 {
            font-family: var(--font-heading);
            font-size: 2rem; font-weight: 800;
            color: var(--text-primary);
            margin: 0 0 .5rem !important;
            display: block !important;
        }
        .tp-signatures__head h2::before { display: none !important; }
        .tp-signatures__head p { color: var(--text-secondary); margin: 0; font-size: .95rem; }

        .tp-signatures__list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: .75rem;
        }
        .tp-signatures__item {
            display: grid;
            grid-template-columns: minmax(180px, 1fr) minmax(200px, 1.4fr) auto;
            gap: 1.25rem;
            align-items: center;
            padding: 1.1rem 1.25rem;
            background: var(--bg-surface);
            border: 1px solid var(--border-base);
            border-left: 3px solid var(--tp-primary);
            border-radius: 10px;
        }
        .tp-signatures__tipo {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            flex-wrap: wrap;
            font-family: var(--font-heading);
            font-weight: 700;
            font-size: .85rem;
            color: var(--text-primary);
        }
        .tp-signatures__tipo i { width: 16px; height: 16px; color: var(--tp-primary); }
        .tp-signatures__ver {
            font-family: var(--font-mono, 'JetBrains Mono', monospace);
            font-size: .7rem;
            padding: .15rem .45rem;
            background: var(--bg-nav-hover);
            color: var(--text-secondary);
            border-radius: 999px;
            font-weight: 500;
        }
        .tp-signatures__who strong {
            display: block;
            color: var(--text-primary);
            font-size: .95rem;
            margin-bottom: .15rem;
        }
        .tp-signatures__who span {
            color: var(--text-muted);
            font-size: .8rem;
        }
        .tp-signatures__when {
            text-align: right;
            font-size: .85rem;
            color: var(--text-secondary);
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: .25rem;
        }
        .tp-signatures__when time { font-variant-numeric: tabular-nums; }
        .tp-signatures__hash {
            font-family: var(--font-mono, 'JetBrains Mono', monospace);
            font-size: .7rem;
            color: var(--text-muted);
            background: var(--bg-nav-hover);
            padding: .15rem .45rem;
            border-radius: 6px;
            cursor: help;
        }
        .tp-signatures__foot {
            margin-top: 2rem;
            padding: .9rem 1.1rem;
            background: color-mix(in srgb, var(--tp-primary) 8%, transparent);
            border: 1px solid color-mix(in srgb, var(--tp-primary) 25%, transparent);
            border-radius: 10px;
            color: var(--text-secondary);
            font-size: .82rem;
            display: flex;
            align-items: center;
            gap: .6rem;
        }
        .tp-signatures__foot i { width: 16px; height: 16px; color: var(--tp-primary); flex: 0 0 auto; }

        @media (max-width: 640px) {
            .tp-signatures__item {
                grid-template-columns: 1fr;
                gap: .75rem;
            }
            .tp-signatures__when {
                text-align: left;
                align-items: flex-start;
                flex-direction: row;
                flex-wrap: wrap;
                gap: .75rem;
            }
        }

        /* =============================================
           Onboarding coachmark: apunta al FAB comentarios
           ============================================= */
        .tp-onboarding {
            position: fixed;
            right: 1.5rem;
            bottom: 5.25rem;
            max-width: 340px;
            padding: 1.25rem 1.35rem 1.15rem;
            background: var(--bg-surface);
            border: 1px solid var(--border-strong);
            border-radius: 14px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, .5), 0 2px 6px rgba(0, 0, 0, .3);
            z-index: 2000;
            animation: tpOnbIn .45s cubic-bezier(.16,1,.3,1);
        }
        [data-theme="light"] .tp-onboarding {
            box-shadow: 0 20px 50px rgba(20, 20, 20, .18), 0 2px 6px rgba(20, 20, 20, .08);
        }
        @keyframes tpOnbIn {
            from { opacity: 0; transform: translateY(12px) scale(.97); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }
        .tp-onboarding::after {
            content: '';
            position: absolute;
            right: 28px; bottom: -7px;
            width: 14px; height: 14px;
            background: var(--bg-surface);
            border-right: 1px solid var(--border-strong);
            border-bottom: 1px solid var(--border-strong);
            transform: rotate(45deg);
        }
        .tp-onboarding__title {
            display: flex;
            align-items: center;
            gap: .55rem;
            font-family: var(--font-heading);
            font-weight: 700;
            font-size: 1rem;
            margin-bottom: .6rem;
            color: var(--text-primary);
            padding-right: 1.5rem;
        }
        .tp-onboarding__title i {
            width: 20px; height: 20px;
            color: var(--tp-primary);
            flex: 0 0 auto;
        }
        .tp-onboarding__body {
            font-size: .85rem;
            line-height: 1.55;
            color: var(--text-secondary);
            margin-bottom: 1rem;
        }
        .tp-onboarding__body strong { color: var(--text-primary); font-weight: 600; }
        .tp-onboarding__ok {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            padding: .6rem 1rem;
            background: var(--tp-primary);
            color: #0e0e0e;
            border: 0;
            border-radius: 8px;
            font-family: var(--font-heading);
            font-weight: 700;
            font-size: .85rem;
            letter-spacing: .01em;
            cursor: pointer;
            transition: filter .15s ease;
        }
        [data-theme="light"] .tp-onboarding__ok { color: #ffffff; }
        .tp-onboarding__ok:hover { filter: brightness(1.08); }
        .tp-onboarding__dismiss {
            position: absolute;
            top: .6rem; right: .6rem;
            width: 28px; height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            background: transparent;
            color: var(--text-muted);
            border-radius: 6px;
            cursor: pointer;
            transition: background .15s ease, color .15s ease;
        }
        .tp-onboarding__dismiss i { width: 14px; height: 14px; }
        .tp-onboarding__dismiss:hover {
            background: var(--bg-nav-hover);
            color: var(--text-primary);
        }

        @media (max-width: 768px) {
            .tp-onboarding {
                left: .75rem; right: .75rem;
                bottom: 4.5rem;
                max-width: none;
            }
            .tp-onboarding::after { right: 30px; }
        }

        .content-wrapper h2 {
            font-family: var(--font-heading);
            font-size: 1.7rem;
            margin-top: 4.5rem;
            margin-bottom: 1.75rem;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 1rem;
            scroll-margin-top: 3rem;
        }

        .content-wrapper h2::before {
            content: '';
            width: 16px;
            height: 4px;
            background: var(--tp-primary);
            border-radius: 2px;
        }

        .content-wrapper h3 {
            font-family: var(--font-heading);
            font-size: 1.4rem;
            margin: 3rem 0 1rem;
            color: var(--text-primary);
        }

        .content-wrapper p {
            margin-bottom: 1.5rem;
            font-size: 1.05rem;
        }

        .content-wrapper ul {
            margin-bottom: 2rem;
            padding-left: 1.5rem;
        }

        .content-wrapper li {
            margin-bottom: 0.75rem;
        }

        .progress-bar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1100;
        }

        .progress-fill {
            height: 100%;
            background: var(--tp-primary);
            width: 0%;
            transition: width 0.1s;
            box-shadow: 0 0 10px rgba(93, 255, 191, 0.4);
        }

        .progress-label {
            position: fixed;
            top: 14px;
            right: 1.5rem;
            font-family: var(--font-heading);
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-muted);
            background: rgba(14, 14, 14, 0.75);
            backdrop-filter: blur(8px);
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            border: 1px solid var(--border-base);
            z-index: 1050;
            opacity: 0;
            transform: translateY(-4px);
            transition: opacity .25s, transform .25s;
            pointer-events: none;
            max-width: calc(100vw - 3rem);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .progress-label.visible {
            opacity: 1;
            transform: translateY(0);
        }

        @media (max-width: 768px) {
            .progress-label { display: none; }
        }

        /* Theme toggle · en sidebar */
        .theme-toggle {
            display: flex;
            align-items: center;
            gap: .65rem;
            padding: .7rem .9rem;
            margin-top: 1rem;
            background: transparent;
            border: 1px solid var(--border-base);
            border-radius: 8px;
            color: var(--text-muted);
            font-family: inherit;
            font-size: .8rem;
            font-weight: 500;
            cursor: pointer;
            transition: all .18s ease;
            width: 100%;
            text-align: left;
        }
        .theme-toggle:hover {
            color: var(--text-primary);
            border-color: var(--border-strong);
            background: var(--bg-nav-hover);
        }
        .theme-toggle__icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            color: var(--tp-primary);
            flex-shrink: 0;
        }
        .theme-toggle__icon i[data-lucide] {
            width: 16px;
            height: 16px;
            stroke-width: 2;
        }
        [data-theme="dark"] .theme-toggle__icon--light,
        :root:not([data-theme="light"]) .theme-toggle__icon--light {
            display: none;
        }
        [data-theme="light"] .theme-toggle__icon--dark {
            display: none;
        }
        .theme-toggle__label::before {
            content: 'Tema claro';
        }
        [data-theme="light"] .theme-toggle__label::before {
            content: 'Tema oscuro';
        }

        /* Light-mode overrides para hardcodes residuales del shell */
        [data-theme="light"] .modal-box { box-shadow: 0 20px 60px -20px rgba(20, 20, 20, .2); }
        [data-theme="light"] .mobile-header { border-bottom-color: var(--border-base); }
        [data-theme="light"] .mobile-nav-overlay { background: var(--bg-base); }
        [data-theme="light"] .progress-bar { background: var(--bg-nav-hover); }
        [data-theme="light"] .progress-label {
            background: var(--bg-surface);
            color: var(--text-primary);
            border: 1px solid var(--border-base);
            box-shadow: 0 4px 16px -4px rgba(20, 20, 20, .1);
        }
        [data-theme="light"] aside { border-right-color: var(--border-base); }
        [data-theme="light"] .team-card,
        [data-theme="light"] .cta-block {
            background: var(--bg-surface);
            border-color: var(--border-base);
            box-shadow: 0 1px 2px rgba(20, 20, 20, .04);
        }
        [data-theme="light"] code,
        [data-theme="light"] pre {
            background: var(--bg-nav-hover);
            color: var(--text-primary);
        }
        /* CTA botones en modo claro — evita texto gris claro sobre fondo claro */
        [data-theme="light"] .btn-cta-secondary {
            color: var(--text-primary);
            border-color: var(--border-strong, var(--border-base));
            background: var(--bg-surface);
        }
        [data-theme="light"] .btn-cta-secondary:hover {
            background: var(--bg-nav-hover);
            color: var(--text-primary);
            border-color: var(--text-secondary);
        }
        [data-theme="light"] .btn-cta-secondary i,
        [data-theme="light"] .btn-cta-secondary svg { color: var(--text-primary); }
        [data-theme="light"] .cta-block p,
        [data-theme="light"] .cta-block { color: var(--text-secondary); }

        /* Hierarchical sidebar nav — H2 parents + H3 children */
        .nav-item-children {
            list-style: none;
            max-height: 0;
            overflow: hidden;
            transition: max-height .3s ease;
            margin: 0;
            padding-left: 0;
        }

        .nav-item.is-open > .nav-item-children {
            max-height: 600px;
        }

        .nav-link--sub {
            display: flex;
            align-items: center;
            padding: 0.45rem 1rem 0.45rem 2.2rem;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.78rem;
            font-weight: 400;
            border-radius: 8px;
            transition: all 0.15s;
            position: relative;
        }

        .nav-link--sub::before {
            content: '';
            position: absolute;
            left: 1.15rem;
            top: 50%;
            width: 6px;
            height: 1px;
            background: var(--border-strong);
        }

        .nav-link--sub:hover {
            color: #DDD;
            background: rgba(255,255,255,0.02);
        }

        .nav-link--sub.active {
            color: var(--tp-primary);
            font-weight: 500;
        }

        .nav-link .nav-num {
            font-family: var(--font-heading);
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--text-muted);
            margin-right: 0.5rem;
            letter-spacing: 0.04em;
            flex-shrink: 0;
            min-width: 22px;
        }

        .nav-link.active .nav-num {
            color: var(--tp-primary);
        }

        .nav-link .nav-caret {
            margin-left: auto;
            width: 14px;
            height: 14px;
            opacity: 0.5;
            transition: transform .2s;
            flex-shrink: 0;
        }

        .nav-item.is-open > .nav-link .nav-caret {
            transform: rotate(90deg);
        }

        /* Content components (.tp-grid, .tp-card, .tp-sitemap, .tp-callout, etc.)
           live in /master/doc-library.css — do NOT duplicate here. */

        /* Team Cards */
        .team-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 1.5rem;
            margin: 3rem 0;
            width: 100%;
        }

        .team-card {
            background: var(--bg-surface);
            border: 1px solid #1F1F1F;
            border-radius: 20px;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            max-width: 280px;
            margin: 0 auto;
        }

        .team-card:hover {
            transform: translateY(-8px);
            border-color: var(--tp-primary);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.4);
        }

        .team-photo-container {
            position: relative;
            width: 100%;
            aspect-ratio: 1/1;
            overflow: hidden;
            background: var(--bg-base);
        }

        .team-photo-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
            filter: grayscale(20%);
        }

        .team-card:hover .team-photo-container img {
            transform: scale(1.05);
            filter: grayscale(0%);
        }

        .team-card-info {
            padding: 1.25rem;
            position: relative;
        }

        .team-card-info::before {
            content: '';
            position: absolute;
            top: 0;
            left: 1.25rem;
            right: 1.25rem;
            height: 1px;
            background: linear-gradient(90deg, #1F1F1F, transparent);
        }

        .team-role {
            display: block;
            font-family: var(--font-heading);
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--tp-primary);
            margin-bottom: 0.5rem;
        }

        .team-name {
            display: block;
            font-family: var(--font-heading);
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .team-desc {
            font-size: 0.85rem;
            color: #888;
            line-height: 1.5;
        }



        /* Mobile Header */
        .mobile-header {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 64px;
            background: rgba(14, 14, 14, 0.8);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-base);
            z-index: 1000;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
        }

        .mobile-logo {
            height: 24px;
        }

        .menu-toggle {
            background: none;
            border: none;
            color: var(--text-primary);
            cursor: pointer;
            padding: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Mobile Overlay Menu */
        .mobile-nav-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: var(--bg-base);
            z-index: 2000;
            padding: 2rem 1.5rem;
            overflow-y: auto;
            transform: translateX(100%);
            transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .mobile-nav-overlay.open {
            display: block;
            transform: translateX(0);
        }

        .mobile-nav-list {
            list-style: none;
        }

        .mobile-nav-item {
            margin-bottom: 0.15rem;
            border-bottom: 1px solid var(--border-subtle, rgba(255, 255, 255, 0.04));
        }

        .mobile-nav-row {
            display: flex;
            align-items: stretch;
            gap: 0.25rem;
        }

        .mobile-nav-link {
            display: flex;
            align-items: center;
            flex: 1;
            min-width: 0;
            padding: 1.15rem 0.5rem;
            color: var(--text-primary);
            text-decoration: none;
            font-size: 1.05rem;
            font-weight: 600;
            font-family: var(--font-heading);
            gap: 0.75rem;
        }
        .mobile-nav-link span {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .mobile-nav-link.active {
            color: var(--tp-primary);
        }

        .mobile-nav-caret {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            flex-shrink: 0;
            background: transparent;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 0;
            border-radius: 8px;
            transition: background .18s ease, color .18s ease, transform .25s ease;
        }
        .mobile-nav-caret:hover,
        .mobile-nav-caret:focus-visible {
            background: var(--bg-nav-hover);
            color: var(--text-primary);
            outline: none;
        }
        .mobile-nav-caret svg {
            width: 18px;
            height: 18px;
            transition: transform .28s cubic-bezier(.4, 0, .2, 1);
        }
        .mobile-nav-item.is-open > .mobile-nav-row .mobile-nav-caret svg {
            transform: rotate(90deg);
        }

        .mobile-nav-children {
            list-style: none;
            margin: 0;
            padding: 0;
            max-height: 0;
            overflow: hidden;
            transition: max-height .32s cubic-bezier(.4, 0, .2, 1);
        }
        .mobile-nav-item.is-open > .mobile-nav-children {
            max-height: 900px;
        }

        .mobile-nav-sublink {
            display: flex;
            align-items: center;
            padding: 0.85rem 0.5rem 0.85rem 2.25rem;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.92rem;
            font-weight: 500;
            border-left: 1px solid var(--border-base);
            margin-left: 0.9rem;
            position: relative;
        }
        .mobile-nav-sublink::before {
            content: '';
            position: absolute;
            left: -1px;
            top: 50%;
            width: 10px;
            height: 1px;
            background: var(--border-base);
        }
        .mobile-nav-sublink:hover,
        .mobile-nav-sublink:focus-visible {
            color: var(--text-primary);
            outline: none;
        }
        .mobile-nav-sublink.active {
            color: var(--tp-primary);
            border-left-color: var(--tp-primary);
        }

        .mobile-nav-num {
            font-family: var(--font-mono, 'JetBrains Mono', monospace);
            font-size: 0.72rem;
            font-weight: 500;
            color: var(--text-muted);
            background: var(--bg-nav-hover);
            padding: 0.18rem 0.45rem;
            border-radius: 4px;
            flex-shrink: 0;
            letter-spacing: 0.02em;
        }
        .mobile-nav-link.active .mobile-nav-num {
            color: var(--tp-primary);
            background: rgba(var(--tp-primary-rgb), 0.1);
        }

        @media (max-width: 1024px) {
            aside {
                width: 280px;
            }

            main {
                margin-left: 280px;
                padding: 4rem 2rem;
            }
        }

        @media (max-width: 768px) {
            aside {
                display: none;
            }

            main {
                margin-left: 0;
                padding: 6rem 1.5rem 3rem;
            }

            .progress-bar {
                top: 64px;
            }

            h1 {
                font-size: 1.8rem;
            }

            .mobile-header {
                display: flex;
            }

            .content-wrapper h2 {
                font-size: 1.4rem;
                margin-top: 3rem;
            }
        }

        /* ===== tp-mermaid · wrapper para diagramas Mermaid ===== */
        .tp-mermaid {
            margin: 2rem 0;
            padding: 1.5rem 1rem;
            background: var(--bg-surface, #141414);
            border: 1px solid var(--border-base, #1f1f1f);
            border-radius: var(--radius-lg, 14px);
            overflow-x: auto;
            text-align: center;
        }
        .tp-mermaid .mermaid { display: inline-block; min-width: 100%; }
        .tp-mermaid svg { max-width: 100%; height: auto; }
        .tp-mermaid__caption {
            display: block;
            margin-top: .75rem;
            font-size: .8rem;
            color: var(--text-muted, #8a8a8a);
            font-style: italic;
        }

        /* ===== tp-tabs · pestañas interactivas ===== */
        .tp-tabs { margin: 1.5rem 0 2rem; }
        .tp-tabs__nav {
            display: flex; flex-wrap: wrap; gap: .25rem;
            border-bottom: 1px solid var(--border-strong, #2a2a2a);
            margin-bottom: 1.25rem;
        }
        .tp-tabs__btn {
            background: transparent; border: none; color: var(--text-secondary, #b3b3b3);
            font-family: var(--font-heading, 'Plus Jakarta Sans'), sans-serif;
            font-weight: 600; font-size: .9rem; padding: .75rem 1.1rem;
            cursor: pointer; border-bottom: 2px solid transparent;
            margin-bottom: -1px; transition: color .15s, border-color .15s;
            display: inline-flex; align-items: center; gap: .5rem;
        }
        .tp-tabs__btn:hover { color: var(--text-primary, #f5f5f5); }
        .tp-tabs__btn[aria-selected="true"] {
            color: var(--mint, #5dffbf);
            border-bottom-color: var(--mint, #5dffbf);
        }
        .tp-tabs__btn i[data-lucide] { width: 16px; height: 16px; }
        .tp-tabs__panel { display: none; animation: tp-tab-fade .2s ease; }
        .tp-tabs__panel[aria-hidden="false"] { display: block; }
        @keyframes tp-tab-fade { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }

        /* ===== tp-bar-chart · barras CSS refined ===== */
        .tp-bar-chart { margin: 1.75rem 0 2rem; display: flex; flex-direction: column; gap: .55rem; }
        .tp-bar-chart__divider {
            display: flex; align-items: center; gap: .65rem;
            font-size: .7rem; font-weight: 700; color: var(--text-muted, #8a8a8a);
            text-transform: uppercase; letter-spacing: .08em;
            margin: .9rem 0 .35rem;
        }
        .tp-bar-chart__divider::after {
            content: ''; flex: 1; height: 1px;
            background: linear-gradient(90deg, var(--border-strong, #2a2a2a), transparent);
        }
        .tp-bar-chart__row {
            display: grid; grid-template-columns: 160px 1fr auto;
            gap: 1.1rem; align-items: center;
            padding: .15rem 0;
        }
        .tp-bar-chart__label {
            font-size: .82rem;
            color: var(--text-secondary, #b3b3b3);
            font-weight: 500;
            letter-spacing: .005em;
        }
        .tp-bar-chart__track {
            background: rgba(255,255,255,.025);
            border: 1px solid rgba(255,255,255,.04);
            border-radius: 999px; height: 10px;
            overflow: hidden; position: relative;
        }
        [data-theme="light"] .tp-bar-chart__track {
            background: rgba(0,0,0,.035);
            border-color: rgba(0,0,0,.06);
        }
        .tp-bar-chart__fill {
            height: 100%; border-radius: 999px;
            background: linear-gradient(90deg, rgba(93,255,191,.9), rgba(93,255,191,.45));
            box-shadow: 0 0 12px rgba(93,255,191,.18);
            transition: width .6s cubic-bezier(.2,.8,.2,1);
        }
        .tp-bar-chart__fill--warn { background: linear-gradient(90deg, rgba(251,191,36,.55), rgba(251,191,36,.2)); box-shadow: none; }
        .tp-bar-chart__fill--alt { background: linear-gradient(90deg, rgba(120,170,255,.5), rgba(120,170,255,.18)); box-shadow: none; }
        .tp-bar-chart__fill--muted { background: linear-gradient(90deg, rgba(148,163,184,.28), rgba(148,163,184,.08)); box-shadow: none; }
        .tp-bar-chart__fill--accent { background: linear-gradient(90deg, rgba(93,255,191,.7), rgba(93,255,191,.25)); box-shadow: 0 0 10px rgba(93,255,191,.15); }
        .tp-bar-chart__value {
            font-size: .85rem; font-weight: 600;
            color: var(--text-primary, #f5f5f5);
            min-width: 36px; text-align: right;
            font-variant-numeric: tabular-nums;
            font-feature-settings: "tnum";
        }
        @media (max-width: 560px) {
            .tp-bar-chart__row { grid-template-columns: 1fr auto; gap: .35rem .75rem; }
            .tp-bar-chart__track { grid-column: 1 / -1; }
        }

        /* ===== tp-journey-v2 · grid de fases con pasos numerados + satisfacción ===== */
        .tp-journey-v2 {
            margin: 2rem 0;
            padding: 1.75rem;
            background: linear-gradient(180deg, var(--bg-surface, #141414), var(--bg-base, #0e0e0e));
            border: 1px solid var(--border-base, #1f1f1f);
            border-radius: 14px;
        }
        [data-theme="light"] .tp-journey-v2 {
            background: var(--bg-surface, #fff);
            border-color: var(--border-base, #E4E2DC);
        }
        .tp-journey-v2__head { margin-bottom: 1.4rem; }
        .tp-journey-v2__title {
            font-family: var(--font-heading, 'Plus Jakarta Sans'), sans-serif;
            font-size: 1rem; font-weight: 700;
            color: var(--text-primary); margin: 0 0 .25rem;
            letter-spacing: -0.01em;
        }
        .tp-journey-v2__sub { font-size: .82rem; color: var(--text-muted); }
        .tp-journey-v2__phases {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
        }
        .tp-journey-v2__phase {
            padding: 1rem 1.1rem 1.1rem;
            background: rgba(255,255,255,.02);
            border: 1px solid var(--border-base, #1f1f1f);
            border-top: 3px solid var(--phase-accent, var(--mint, #5dffbf));
            border-radius: 10px;
        }
        [data-theme="light"] .tp-journey-v2__phase {
            background: rgba(0,0,0,.015);
        }
        .tp-journey-v2__phase-head {
            display: flex; align-items: baseline; gap: .55rem;
            margin-bottom: .9rem; padding-bottom: .65rem;
            border-bottom: 1px dashed var(--border-base, #1f1f1f);
        }
        .tp-journey-v2__phase-num {
            font-family: var(--font-mono, ui-monospace, monospace);
            font-size: .72rem; color: var(--text-muted); letter-spacing: .04em;
            font-variant-numeric: tabular-nums;
        }
        .tp-journey-v2__phase-title {
            font-family: var(--font-heading, 'Plus Jakarta Sans'), sans-serif;
            font-size: .92rem; font-weight: 700;
            color: var(--phase-accent, var(--mint, #5dffbf));
            letter-spacing: -0.005em;
        }
        .tp-journey-v2__steps { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: .55rem; }
        .tp-journey-v2__step {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: .6rem; align-items: center;
            font-size: .82rem;
            color: var(--text-secondary, #b3b3b3);
        }
        .tp-journey-v2__step-dot {
            width: 8px; height: 8px; border-radius: 999px;
            background: var(--step-color, var(--text-muted));
            box-shadow: 0 0 6px var(--step-color, transparent);
            flex-shrink: 0;
        }
        .tp-journey-v2__step[data-score="5"] { --step-color: #5dffbf; }
        .tp-journey-v2__step[data-score="4"] { --step-color: #8de0b5; }
        .tp-journey-v2__step[data-score="3"] { --step-color: #e0b35d; }
        .tp-journey-v2__step[data-score="2"] { --step-color: #e08c5d; }
        .tp-journey-v2__step[data-score="1"] { --step-color: #e05d5d; }
        .tp-journey-v2__step-label { color: var(--text-primary); font-weight: 500; }
        .tp-journey-v2__step-score {
            font-family: var(--font-mono, ui-monospace, monospace);
            font-size: .7rem;
            font-variant-numeric: tabular-nums;
            color: var(--step-color);
            background: rgba(255,255,255,.03);
            padding: .1rem .45rem; border-radius: 999px;
            border: 1px solid color-mix(in srgb, var(--step-color) 20%, transparent);
            font-weight: 600;
        }
        [data-theme="light"] .tp-journey-v2__step-score { background: rgba(0,0,0,.03); }
        .tp-journey-v2__legend {
            margin-top: 1.1rem; padding-top: .85rem;
            border-top: 1px dashed var(--border-base, #1f1f1f);
            display: flex; flex-wrap: wrap; gap: 1rem;
            font-size: .7rem; color: var(--text-muted);
        }
        .tp-journey-v2__legend-item { display: inline-flex; align-items: center; gap: .4rem; }
        .tp-journey-v2__legend-dot {
            width: 8px; height: 8px; border-radius: 999px;
            flex-shrink: 0;
            box-shadow: 0 0 6px currentColor;
        }
    </style>

    <!-- Mermaid.js · diagramas interactivos en documentos funcionales -->
    <script src="https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js"></script>
    <script>
        (function () {
            if (!window.mermaid) return;
            var isDark = !document.documentElement.hasAttribute('data-theme') ||
                         document.documentElement.getAttribute('data-theme') !== 'light';
            window.mermaid.initialize({
                startOnLoad: false,
                theme: isDark ? 'dark' : 'neutral',
                fontFamily: "'Inter', system-ui, sans-serif",
                themeVariables: {
                    primaryColor: '#5dffbf',
                    primaryTextColor: isDark ? '#f5f5f5' : '#0e0e0e',
                    primaryBorderColor: '#5dffbf',
                    lineColor: isDark ? '#8a8a8a' : '#2a2a2a',
                    secondaryColor: isDark ? '#191919' : '#f5f5f5',
                    tertiaryColor: isDark ? '#141414' : '#ffffff',
                    background: isDark ? '#141414' : '#ffffff',
                    mainBkg: isDark ? '#191919' : '#f5f5f5',
                    secondBkg: isDark ? '#1f1f1f' : '#eeeeee',
                    textColor: isDark ? '#f5f5f5' : '#0e0e0e',
                    nodeBorder: isDark ? '#2a2a2a' : '#cccccc',
                    clusterBkg: isDark ? '#141414' : '#f9f9f9',
                    clusterBorder: isDark ? '#2a2a2a' : '#cccccc',
                    actorBkg: isDark ? '#191919' : '#f5f5f5',
                    actorBorder: '#5dffbf',
                    actorTextColor: isDark ? '#f5f5f5' : '#0e0e0e',
                    signalColor: isDark ? '#b3b3b3' : '#333',
                    signalTextColor: isDark ? '#f5f5f5' : '#0e0e0e',
                    labelBoxBkgColor: isDark ? '#141414' : '#fff',
                    labelBoxBorderColor: '#5dffbf',
                    labelTextColor: isDark ? '#f5f5f5' : '#0e0e0e',
                    noteBkgColor: isDark ? '#1f1f1f' : '#fffbdf',
                    noteBorderColor: '#5dffbf',
                    noteTextColor: isDark ? '#f5f5f5' : '#0e0e0e'
                },
                flowchart: { htmlLabels: true, curve: 'basis', padding: 16 },
                sequence: { useMaxWidth: true, actorMargin: 60 }
            });
            // Transforma bloques Mermaid 'journey' → componente tp-journey-v2 (más legible)
            function transformMermaidJourneys() {
                var PHASE_COLORS = ['#5dffbf', '#78aaff', '#c084fc', '#ffb800', '#ff8c8c'];
                document.querySelectorAll('pre.mermaid, .mermaid').forEach(function (el) {
                    var raw = (el.getAttribute('data-original') || el.textContent || '').trim();
                    if (!/^journey\b/i.test(raw)) return;

                    var lines = raw.split('\n').map(function(s){ return s.replace(/\r/g,'').trimEnd(); });
                    var title = '';
                    var phases = [];
                    var currentPhase = null;
                    for (var i = 0; i < lines.length; i++) {
                        var line = lines[i];
                        var trimmed = line.trim();
                        if (!trimmed || /^journey$/i.test(trimmed)) continue;
                        var mt = trimmed.match(/^title\s+(.+)$/i);
                        if (mt) { title = mt[1].trim(); continue; }
                        var ms = trimmed.match(/^section\s+(.+)$/i);
                        if (ms) {
                            currentPhase = { name: ms[1].trim(), steps: [] };
                            phases.push(currentPhase);
                            continue;
                        }
                        // Paso: "Buscar curso: 4: Alumno"
                        var mstep = trimmed.match(/^(.+?)\s*:\s*(\d+)\s*:\s*(.+)$/);
                        if (mstep && currentPhase) {
                            currentPhase.steps.push({
                                label: mstep[1].trim(),
                                score: parseInt(mstep[2], 10) || 3,
                                actor: mstep[3].trim()
                            });
                        }
                    }
                    if (phases.length === 0) return;

                    // Construir HTML del nuevo componente
                    var wrap = document.createElement('div');
                    wrap.className = 'tp-journey-v2';
                    var html = '';
                    if (title) {
                        html += '<div class="tp-journey-v2__head">';
                        html += '<h4 class="tp-journey-v2__title">' + escHtml(title) + '</h4>';
                        html += '</div>';
                    }
                    html += '<div class="tp-journey-v2__phases">';
                    phases.forEach(function (phase, idx) {
                        var color = PHASE_COLORS[idx % PHASE_COLORS.length];
                        html += '<div class="tp-journey-v2__phase" style="--phase-accent: ' + color + ';">';
                        html += '<div class="tp-journey-v2__phase-head">';
                        html += '<span class="tp-journey-v2__phase-num">' + String(idx + 1).padStart(2, '0') + '</span>';
                        html += '<span class="tp-journey-v2__phase-title">' + escHtml(phase.name) + '</span>';
                        html += '</div>';
                        html += '<ul class="tp-journey-v2__steps">';
                        phase.steps.forEach(function (step) {
                            html += '<li class="tp-journey-v2__step" data-score="' + step.score + '" title="Satisfacción: ' + step.score + '/5 · ' + escHtml(step.actor) + '">';
                            html += '<span class="tp-journey-v2__step-dot"></span>';
                            html += '<span class="tp-journey-v2__step-label">' + escHtml(step.label) + '</span>';
                            html += '<span class="tp-journey-v2__step-score">' + step.score + '/5</span>';
                            html += '</li>';
                        });
                        html += '</ul>';
                        html += '</div>';
                    });
                    html += '</div>';
                    // Leyenda
                    html += '<div class="tp-journey-v2__legend">';
                    html += '<span class="tp-journey-v2__legend-item"><span class="tp-journey-v2__legend-dot" style="background:#5dffbf;color:#5dffbf;"></span>Excelente (5)</span>';
                    html += '<span class="tp-journey-v2__legend-item"><span class="tp-journey-v2__legend-dot" style="background:#8de0b5;color:#8de0b5;"></span>Bueno (4)</span>';
                    html += '<span class="tp-journey-v2__legend-item"><span class="tp-journey-v2__legend-dot" style="background:#e0b35d;color:#e0b35d;"></span>Aceptable (3)</span>';
                    html += '<span class="tp-journey-v2__legend-item"><span class="tp-journey-v2__legend-dot" style="background:#e05d5d;color:#e05d5d;"></span>Fricción (&lt;3)</span>';
                    html += '</div>';
                    wrap.innerHTML = html;

                    // Reemplazar el pre o el contenedor tp-mermaid superior si existe
                    var container = el.closest('.tp-mermaid') || el;
                    container.parentNode.replaceChild(wrap, container);
                });

                function escHtml(s) {
                    return String(s).replace(/[&<>"']/g, function(c){
                        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
                    });
                }
            }

            document.addEventListener('DOMContentLoaded', function () {
                transformMermaidJourneys();  // primero: journeys → custom component
                window.mermaid.run({ querySelector: '.mermaid' }).catch(function(e){ console.warn('Mermaid:', e); });
            });
            // Re-render al cambiar tema
            var obs = new MutationObserver(function () {
                var nowDark = !document.documentElement.hasAttribute('data-theme') ||
                              document.documentElement.getAttribute('data-theme') !== 'light';
                document.querySelectorAll('.mermaid[data-original]').forEach(function(el){
                    el.innerHTML = el.getAttribute('data-original');
                    el.removeAttribute('data-processed');
                });
                document.querySelectorAll('.mermaid:not([data-original])').forEach(function(el){
                    el.setAttribute('data-original', el.textContent);
                });
                window.mermaid.initialize({ startOnLoad: false, theme: nowDark ? 'dark' : 'neutral' });
                window.mermaid.run({ querySelector: '.mermaid' }).catch(function(){});
            });
            obs.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
        })();

        /* tp-tabs · interactividad */
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.tp-tabs__btn');
            if (!btn) return;
            var tabs = btn.closest('.tp-tabs');
            if (!tabs) return;
            var target = btn.getAttribute('data-tab');
            tabs.querySelectorAll('.tp-tabs__btn').forEach(function(b){ b.setAttribute('aria-selected', b === btn ? 'true' : 'false'); });
            tabs.querySelectorAll('.tp-tabs__panel').forEach(function(p){ p.setAttribute('aria-hidden', p.getAttribute('data-tab') === target ? 'false' : 'true'); });
        });
    </script>
</head>

<body>
    <div class="mobile-header">
        <div style="display:flex; align-items:center;">
            <img src="/master/brand/logo-dark.svg" alt="Tres Puntos" class="mobile-logo tp-logo tp-logo--dark">
            <img src="/master/brand/logo-light.svg" alt="Tres Puntos" class="mobile-logo tp-logo tp-logo--light">
            <span class="beta-badge" title="Entorno en desarrollo activo">Beta</span>
        </div>
        <button class="menu-toggle" onclick="toggleMobileMenu()"><i data-lucide="menu"></i></button>
    </div>

    <div class="mobile-nav-overlay" id="mobileNav">
        <div class="mobile-nav-header">
            <img src="/master/brand/logo-dark.svg" alt="Tres Puntos" class="tp-logo tp-logo--dark" style="height: 24px;">
            <img src="/master/brand/logo-light.svg" alt="Tres Puntos" class="tp-logo tp-logo--light" style="height: 24px;">
            <button class="menu-toggle" onclick="toggleMobileMenu()"><i data-lucide="x"></i></button>
        </div>
        <ul class="mobile-nav-list" id="mobile-nav-container"></ul>
    </div>
    <div class="progress-bar">
        <div class="progress-fill" id="progressFill"></div>
    </div>
    <div class="progress-label" id="progressLabel"></div>
    <div class="app-container">
        <aside>
            <div class="sidebar-brand">
                <img src="/master/brand/logo-dark.svg" alt="Tres Puntos" class="tp-logo tp-logo--dark" style="height: 38px;">
                <img src="/master/brand/logo-light.svg" alt="Tres Puntos" class="tp-logo tp-logo--light" style="height: 38px;">
                <div class="beta-stack">
                    <span class="beta-badge" title="Este entorno está en desarrollo activo. Feedback bienvenido.">Beta</span>
                    <span class="beta-badge-sub">by TresPuntos Lab</span>
                </div>
            </div>
            <div class="sidebar-nav-container">
                <ul id="sidebar-nav"></ul>
            </div>
            <button class="theme-toggle" id="themeToggle" type="button" aria-label="Cambiar entre tema claro y oscuro">
                <span class="theme-toggle__icon theme-toggle__icon--dark"><i data-lucide="moon"></i></span>
                <span class="theme-toggle__icon theme-toggle__icon--light"><i data-lucide="sun"></i></span>
                <span class="theme-toggle__label"></span>
            </button>
        </aside>
        <main>
            <div class="content-wrapper">
                <?php
    $dateToUse = !empty($proposal['sent_date']) ? $proposal['sent_date'] : ($proposal['created_at'] ?? '');
    if (!empty($dateToUse) && strtotime($dateToUse)) {
        $timeStr = strtotime($dateToUse);
        $meses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
        $formattedDate = date('j', $timeStr) . ' de ' . $meses[date('n', $timeStr) - 1] . ' de ' . date('Y', $timeStr);
    }
    else {
        $formattedDate = date('Y');
    }
?>
                <h1>
                    <?php echo htmlspecialchars($proposal['client_name']); ?>
                </h1>
                <p class="doc-meta">Documentación Funcional · Versión
                    <?php echo htmlspecialchars($proposal['version'] ?? '1.0'); ?> · Enviado el
                    <?php echo $formattedDate; ?>
                </p>

                <?php
                    $hasPresupuestoTab = ($hasHolded || $hasPdf) && !$isProviderMode;
                    $hasFirmasTab      = !empty($firmas) && !$isProviderMode;
                    $showTabs          = $hasPresupuestoTab || $hasFirmasTab;
                    $presupuestoLabel  = $hasHolded ? ('Presupuesto · ' . htmlspecialchars($holdedDoc['docNumber'] ?? '', ENT_QUOTES, 'UTF-8')) : 'Presupuesto';
                ?>
                <?php if ($showTabs): ?>
                <nav class="doc-tabs" role="tablist" aria-label="Vistas del documento">
                    <button class="doc-tab is-active" type="button" role="tab" data-tab-target="documento" aria-selected="true">
                        <i data-lucide="file-text"></i>
                        <span>Documento</span>
                    </button>
                    <?php if ($hasPresupuestoTab): ?>
                    <button class="doc-tab" type="button" role="tab" data-tab-target="presupuesto" aria-selected="false">
                        <i data-lucide="file-spreadsheet"></i>
                        <span><?php echo $presupuestoLabel; ?></span>
                    </button>
                    <?php endif; ?>
                    <?php if ($hasFirmasTab): ?>
                    <button class="doc-tab" type="button" role="tab" data-tab-target="firmas" aria-selected="false">
                        <i data-lucide="pen-tool"></i>
                        <span>Firmas</span>
                        <span class="doc-tab__count"><?php echo count($firmas); ?></span>
                    </button>
                    <?php endif; ?>
                </nav>
                <?php endif; ?>

                <?php if ($isAdminMode): ?>
                    <div class="tp-admin-banner">
                        <i data-lucide="shield"></i>
                        <span>Estás viendo el documento como <strong>administrador</strong><?= $isProviderMode && $__provider ? ' · sesión del proveedor <strong>' . htmlspecialchars($__provider['nombre']) . '</strong>' : ' · vista del cliente' ?>. Las respuestas que envíes aparecerán firmadas como <strong>Tres Puntos</strong>.</span>
                        <a href="admin.php" class="tp-admin-banner-exit">Salir del modo admin</a>
                    </div>
                    <style>
                        .tp-admin-banner {
                            background: linear-gradient(135deg, rgba(192,132,252,.18), rgba(192,132,252,.04));
                            border: 1px solid rgba(192,132,252,.4);
                            border-radius: 10px;
                            padding: .8rem 1rem;
                            margin: 0 0 1.5rem;
                            display: flex; align-items: center; gap: .7rem;
                            font-size: .85rem; color: var(--text-primary, #f5f5f5);
                        }
                        .tp-admin-banner i[data-lucide] { color: #c084fc; width: 18px; height: 18px; flex-shrink: 0; }
                        .tp-admin-banner strong { color: #c084fc; }
                        .tp-admin-banner-exit {
                            margin-left: auto;
                            background: transparent; border: 1px solid rgba(192,132,252,.4);
                            color: #c084fc; padding: .3rem .75rem; border-radius: 6px;
                            text-decoration: none; font-size: .75rem; font-weight: 600;
                        }
                        .tp-admin-banner-exit:hover { background: rgba(192,132,252,.15); }
                    </style>
                <?php endif; ?>
                <?php if ($isProviderMode && !$isAdminMode): include __DIR__ . '/master/provider-upload.php'; endif; ?>

                <div id="content-area" class="doc-view" data-tab="documento">
                    <?php echo $proposal['html_content']; ?>
                </div>

                <div id="content-areas-extensions">
                    <div class="doc-view" data-tab="documento">
                    <?php if (strpos($proposal['html_content'], 'tp-hide-metodologia') === false): include __DIR__ . '/metodologia.php'; endif; ?>
                    <div id="equipo-extension-area" style="margin-top: 4rem;"></div>
                    <div class="cta-block" id="sec-avanzamos-doc"<?= $isProviderMode ? ' hidden' : '' ?>>
                        <?php if (!$isDocApproved): ?>
                        <h2
                            style="font-family: var(--font-heading); font-size: 2.5rem; color: var(--text-primary); margin-bottom: 1rem; margin-top: 0; display: block;">
                            ¿Avanzamos con el proyecto?</h2>
                        <p>Si este documento refleja correctamente el alcance y los objetivos, podemos
                            validarlo y
                            preparar el presupuesto a medida.</p>
                        <div class="cta-buttons">
                            <button class="btn-cta-primary" onclick="openModal('approve')"><i
                                    data-lucide="check-circle"></i> Aprobar Documento</button>
                            <button class="btn-cta-secondary" onclick="openModal('comment')"><i
                                    data-lucide="message-square"></i> Comentar Cambios</button>
                            <button class="btn-cta-secondary"
                                onclick="Calendly.initPopupWidget({url: 'https://calendly.com/trespuntos/tres-puntos'});return false;"><i
                                    data-lucide="calendar"></i> Agendar videollamada</button>
                        </div>
                        <?php
    else: ?>
                        <div class="success-icon" style="margin-bottom:1rem;"><i data-lucide="check-square"
                                style="width:48px;height:48px;color:var(--tp-primary);"></i></div>
                        <h2 style="font-size: 1.8rem;">Documento funcional aprobado</h2>
                        <?php if (!$hasPdf): ?>
                        <p style="margin-bottom: 2rem;"><strong>En espera:</strong> Ya estamos trabajando
                            minuciosamente
                            en tu presupuesto. Lo subiremos a esta misma página en breve y te notificaremos.
                        </p>
                        <?php
        else: ?>
                        <p style="margin-bottom: 2rem;">Has aprobado satisfactoriamente los detalles de este
                            documento
                            funcional.</p>
                        <?php
        endif; ?>
                        <div class="cta-buttons">
                            <button class="btn-cta-secondary"
                                onclick="Calendly.initPopupWidget({url: 'https://calendly.com/trespuntos/tres-puntos'});return false;"><i
                                    data-lucide="calendar"></i> Agendar videollamada</button>
                        </div>
                        <?php
    endif; ?>
                    </div>
                    </div> <!-- /.doc-view data-tab=documento -->

                    <?php if ($hasPresupuestoTab): ?>
                    <div class="doc-view" data-tab="presupuesto" hidden>
                    <?php if ($hasHolded):
                        // El template espera $holded_doc (snake_case) → alias del arg $holdedDoc.
                        $holded_doc = $holdedDoc;
                        $propuesta  = $proposal;
                        include __DIR__ . '/master/presupuesto-holded.php';
                    ?>
                    <div class="cta-block" id="sec-presupuesto" style="margin-top: 3rem;">
                        <?php if (!$isPdfApproved): ?>
                        <h2 style="font-family: var(--font-heading); font-size: 2.2rem; color: var(--text-primary); margin-bottom: 1rem; margin-top: 0; display: block;">
                            ¿Aprobamos el presupuesto?
                        </h2>
                        <p>Si todo lo anterior cuadra con lo acordado, firma aquí la aceptación y arrancamos.</p>
                        <div class="cta-buttons">
                            <button class="btn-cta-primary" onclick="openModal('approve-pdf')">
                                <i data-lucide="check-circle"></i> Aprobar Presupuesto
                            </button>
                            <button class="btn-cta-secondary" onclick="openModal('reject-pdf')">
                                <i data-lucide="x-circle"></i> Comentar / Pedir cambios
                            </button>
                            <button class="btn-cta-secondary" onclick="Calendly.initPopupWidget({url: 'https://calendly.com/trespuntos/tres-puntos'});return false;">
                                <i data-lucide="calendar"></i> Agendar videollamada
                            </button>
                        </div>
                        <?php else: ?>
                        <div style="margin-bottom:1rem;"><i data-lucide="check-square" style="width:48px;height:48px;color:var(--tp-primary);"></i></div>
                        <h2 style="font-size: 1.8rem; color: var(--tp-primary);">Presupuesto aprobado</h2>
                        <p>🎉 ¡Gracias por la confianza! Nos ponemos con el kickoff ahora mismo.</p>
                        <div class="cta-buttons" style="margin-top: 2rem;">
                            <button class="btn-cta-secondary" onclick="Calendly.initPopupWidget({url: 'https://calendly.com/trespuntos/tres-puntos'});return false;">
                                <i data-lucide="calendar"></i> Agendar kickoff
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php elseif ($hasPdf): ?>
                    <div class="cta-block" id="sec-presupuesto" style="margin-top: 4rem;">
                        <h2
                            style="font-family: var(--font-heading); font-size: 2.5rem; color: var(--text-primary); margin-bottom: 1rem; margin-top: 0; display: block;">
                            Presupuesto de Proyecto</h2>
                        <div
                            style="background: var(--bg-surface); padding: 1rem; border-radius: 16px; border: 1px solid var(--border-base); margin-bottom: 3rem; height: 800px;">
                            <iframe
                                src="/uploads/presupuestos/<?php echo htmlspecialchars($proposal['presupuesto_pdf']); ?>"
                                width="100%" height="100%" style="border:none; border-radius: 8px;"></iframe>
                        </div>

                        <?php if (!$isPdfApproved): ?>
                        <p>Revisa el presupuesto detallado en el visor superior. Si todo es correcto,
                            podemos proceder a
                            la aprobación formal.</p>
                        <div class="cta-buttons">
                            <button class="btn-cta-primary" onclick="openModal('approve-pdf')"><i
                                    data-lucide="check-circle"></i> Aprobar Presupuesto</button>
                            <button class="btn-cta-secondary" onclick="openModal('reject-pdf')"><i
                                    data-lucide="x-circle"></i> Denegar / Cambios</button>
                            <a href="/uploads/presupuestos/<?php echo htmlspecialchars($proposal['presupuesto_pdf']); ?>"
                                download class="btn-cta-secondary"><i data-lucide="download"></i> Descargar
                                PDF</a>
                            <button class="btn-cta-secondary"
                                onclick="Calendly.initPopupWidget({url: 'https://calendly.com/trespuntos/tres-puntos'});return false;"><i
                                    data-lucide="calendar"></i> Agendar videollamada</button>
                        </div>
                        <?php
        else: ?>
                        <div style="margin-bottom:1rem;"><i data-lucide="check-square"
                                style="width:48px;height:48px;color:var(--tp-primary);"></i></div>
                        <h2 style="font-size: 1.8rem; color: var(--tp-primary);">Presupuesto Aprobado</h2>
                        <p>🎉 ¡Gracias por tu confianza! Estamos listos para comenzar con el desarrollo. Nos
                            pondremos
                            en contacto contigo para agendar el kickoff del proyecto.</p>
                        <div class="cta-buttons" style="margin-top: 2rem;">
                            <a href="/uploads/presupuestos/<?php echo htmlspecialchars($proposal['presupuesto_pdf']); ?>"
                                download class="btn-cta-secondary"><i data-lucide="download"></i> Descargar
                                copia
                                PDF</a>
                            <button class="btn-cta-secondary"
                                onclick="Calendly.initPopupWidget({url: 'https://calendly.com/trespuntos/tres-puntos'});return false;"><i
                                    data-lucide="calendar"></i> Agendar videollamada</button>
                        </div>
                        <?php
        endif; ?>
                    </div>
                    <?php
    endif; ?>
                    </div> <!-- /.doc-view data-tab=presupuesto -->
                    <?php endif; ?>

                    <?php if ($hasFirmasTab): ?>
                    <div class="doc-view" data-tab="firmas" hidden>
                        <section class="tp-signatures" aria-labelledby="firmas-heading">
                            <header class="tp-signatures__head">
                                <h2 id="firmas-heading">Registro de firmas</h2>
                                <p>Trazabilidad de las aprobaciones recibidas sobre este documento y su presupuesto.</p>
                            </header>
                            <ol class="tp-signatures__list">
                                <?php foreach ($firmas as $f):
                                    $nombre   = trim(($f['firmante_nombre'] ?? '') . ' ' . ($f['firmante_apellidos'] ?? ''));
                                    if ($nombre === '') $nombre = '—';
                                    $email    = $f['firmante_email'] ?? '';
                                    $hash     = $f['firma_hash'] ?? '';
                                    $hashShort = $hash ? (substr($hash, 0, 4) . '…' . substr($hash, -4)) : '';
                                    $tipo     = $f['tipo'] === 'documento_funcional' ? 'Documento funcional' : 'Presupuesto';
                                    $tipoIcon = $f['tipo'] === 'documento_funcional' ? 'file-text' : 'file-spreadsheet';
                                    $ver      = $f['version_firmada'] ?? '';
                                    $ts       = strtotime($f['aprobado_at'] ?? '');
                                    $fecha    = $ts ? date('d/m/Y', $ts) : '—';
                                    $hora     = $ts ? date('H:i', $ts) : '';
                                ?>
                                <li class="tp-signatures__item">
                                    <div class="tp-signatures__tipo">
                                        <i data-lucide="<?php echo $tipoIcon; ?>"></i>
                                        <span><?php echo $tipo; ?></span>
                                        <?php if ($ver): ?><span class="tp-signatures__ver"><?php echo htmlspecialchars($ver, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                    </div>
                                    <div class="tp-signatures__who">
                                        <strong><?php echo htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <?php if ($email): ?><span><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                    </div>
                                    <div class="tp-signatures__when">
                                        <time datetime="<?php echo htmlspecialchars($f['aprobado_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo $fecha; ?><?php if ($hora): ?> · <?php echo $hora; ?><?php endif; ?>
                                        </time>
                                        <?php if ($hashShort): ?>
                                        <code class="tp-signatures__hash" title="Hash SHA-256 de verificación: <?php echo htmlspecialchars($hash, ENT_QUOTES, 'UTF-8'); ?>">hash <?php echo $hashShort; ?></code>
                                        <?php endif; ?>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ol>
                            <p class="tp-signatures__foot">
                                <i data-lucide="shield-check"></i>
                                Cada firma incluye un hash SHA-256 que permite verificar que el contenido aprobado no ha sido manipulado.
                            </p>
                        </section>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </main>
    </div>

    <!-- Modals -->
    <div class="modal-overlay" id="modal-approve">
        <div class="modal-box">
            <button class="modal-close" onclick="closeModal('approve')"><i data-lucide="x"
                    style="width:20px;height:20px;"></i></button>
            <div id="approve-form">
                <div class="modal-icon green"><i data-lucide="check-circle" style="width:28px;height:28px;"></i></div>
                <h3>Aprobar Documento Funcional</h3>
                <p>Confirmas que el documento refleja correctamente el alcance y objetivos del proyecto. A
                    partir de
                    aquí elaboraremos el presupuesto definitivo en base a lo acordado.</p>
                <div class="tp-sign-fields">
                    <label>Nombre<input type="text" id="sign-doc-nombre" autocomplete="given-name" required></label>
                    <label>Apellidos<input type="text" id="sign-doc-apellidos" autocomplete="family-name" required></label>
                    <label>Email (opcional)<input type="email" id="sign-doc-email" autocomplete="email"></label>
                    <small class="tp-sign-legal">Al pulsar "Firmar y aprobar" dejas constancia de tu conformidad. Guardamos tu nombre, fecha, versión del documento y un hash de verificación. Versión actual: <strong><?php echo htmlspecialchars($proposal['version']); ?></strong></small>
                </div>
                <div class="modal-actions">
                    <button class="btn-modal-secondary" onclick="closeModal('approve')">Cancelar</button>
                    <button class="btn-modal-primary" onclick="submitApproval()"><i data-lucide="pen-tool"
                            style="width:16px;height:16px;"></i> Firmar y aprobar</button>
                </div>
            </div>
            <div class="modal-success" id="approve-success">
                <div class="success-icon" style="display:flex;justify-content:center;margin-bottom:1rem;"><i
                        data-lucide="check-square" style="width:48px;height:48px;color:var(--tp-primary);"></i></div>
                <h4>¡Documento aprobado!</h4>
                <p>Hemos recibido tu validación y <strong>ya estamos trabajando en el presupuesto</strong>.
                    Te
                    contactaremos en breve con la propuesta económica.</p>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modal-comment">
        <div class="modal-box">
            <button class="modal-close" onclick="closeModal('comment')"><i data-lucide="x"
                    style="width:20px;height:20px;"></i></button>
            <div id="comment-form">
                <div class="modal-icon blue"><i data-lucide="message-square" style="width:28px;height:28px;"></i></div>
                <h3>Comentar Cambios</h3>
                <p>Cuéntanos qué aspectos quieres modificar o ampliar. Te responderemos en menos de 24h.</p>
                <textarea class="modal-textarea" id="comment-text"
                    placeholder="Describe aquí tus comentarios, dudas o modificaciones que quieras proponer..."></textarea>
                <div class="modal-actions">
                    <button class="btn-modal-secondary" onclick="closeModal('comment')">Cancelar</button>
                    <button class="btn-modal-primary" onclick="submitComment()"><i data-lucide="send"
                            style="width:16px;height:16px;"></i> Enviar Comentarios</button>
                </div>
            </div>
            <div class="modal-success" id="comment-success">
                <div class="success-icon" style="display:flex;justify-content:center;margin-bottom:1rem;"><i
                        data-lucide="message-circle" style="width:48px;height:48px;color:#7B96FF;"></i>
                </div>
                <h4>¡Comentarios enviados!</h4>
                <p>Hemos recibido tus anotaciones. Jordi revisará el documento y te escribirá en breve.</p>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modal-approve-pdf">
        <div class="modal-box">
            <button class="modal-close" onclick="closeModal('approve-pdf')"><i data-lucide="x"
                    style="width:20px;height:20px;"></i></button>
            <div id="approve-pdf-form">
                <div class="modal-icon green"><i data-lucide="check-circle" style="width:28px;height:28px;"></i></div>
                <h3>Aprobar Presupuesto</h3>
                <p>Al confirmar, daremos por cerrado el presupuesto y te explicaremos los siguientes pasos
                    de
                    facturación y kickoff del proyecto.</p>
                <div class="tp-sign-fields">
                    <label>Nombre<input type="text" id="sign-pdf-nombre" autocomplete="given-name" required></label>
                    <label>Apellidos<input type="text" id="sign-pdf-apellidos" autocomplete="family-name" required></label>
                    <label>Email (opcional)<input type="email" id="sign-pdf-email" autocomplete="email"></label>
                    <small class="tp-sign-legal">Al pulsar "Firmar y aprobar" dejas constancia de tu conformidad con este presupuesto. Guardamos tu nombre, fecha, versión y un hash de verificación.</small>
                </div>
                <div class="modal-actions">
                    <button class="btn-modal-secondary" onclick="closeModal('approve-pdf')">Cancelar</button>
                    <button class="btn-modal-primary" onclick="submitPdfApproval()"><i data-lucide="pen-tool"
                            style="width:16px;height:16px;"></i> Firmar y aprobar</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modal-reject-pdf">
        <div class="modal-box">
            <button class="modal-close" onclick="closeModal('reject-pdf')"><i data-lucide="x"
                    style="width:20px;height:20px;"></i></button>
            <div id="reject-pdf-form">
                <div class="modal-icon blue"><i data-lucide="x-circle" style="width:28px;height:28px;"></i>
                </div>
                <h3>Denegar o Sugerir Cambios</h3>
                <p>Si hay aspectos del presupuesto que quieres modificar, coméntanoslo y lo revisaremos
                    juntos.</p>
                <textarea class="modal-textarea" id="reject-pdf-text"
                    placeholder="Motivos, dudas o ajustes deseados..."></textarea>
                <div class="modal-actions">
                    <button class="btn-modal-secondary" onclick="closeModal('reject-pdf')">Cancelar</button>
                    <button class="btn-modal-primary" onclick="submitPdfRejection()"><i data-lucide="send"
                            style="width:16px;height:16px;"></i> Enviar Respuesta</button>
                </div>
            </div>
            <div class="modal-success" id="reject-pdf-success">
                <div class="success-icon" style="display:flex;justify-content:center;margin-bottom:1rem;"><i
                        data-lucide="message-circle" style="width:48px;height:48px;color:#7B96FF;"></i>
                </div>
                <h4>¡Mensaje enviado!</h4>
                <p>Analizaremos tus comentarios y te daremos respuesta lo antes posible.</p>
            </div>
        </div>
    </div>

    <script>
                                        const rawTeamData = <?php echo json_encode($team ?: []); ?>;
                                        const TEAM_DATA = Array.isArray(rawTeamData) ? rawTeamData : [];

                                        function injectTeamSection() {
            const TEAM_GRID_ID = 'team-grid-injected';
                                        if (document.getElementById(TEAM_GRID_ID)) return;
                                        const area = document.getElementById('content-area') || document;
                                        let mountPoint = document.getElementById('equipo');
                                        let teamHeader = null;

                                        if (mountPoint) {
                                            teamHeader = mountPoint.querySelector('h2, h3');
                                        if (!teamHeader) {
                                            teamHeader = document.createElement('h2');
                                        teamHeader.innerText = 'Equipo';
                                        mountPoint.prepend(teamHeader);
                }
            } else {
                const headers = Array.from(area.querySelectorAll('h2, h3'));
                teamHeader = headers.find(h => {
                    const text = h.innerText ? h.innerText.toLowerCase() : '';
                                        return text.includes('equipo') || text.includes('quiénes somos') || text.includes('quiénes formamos');
                });
            }

                                        if (!TEAM_DATA || TEAM_DATA.length === 0) {
                if (mountPoint) mountPoint.style.display = 'none';
                                        else if (teamHeader) teamHeader.style.display = 'none';
                                        return;
            }

                                        if (!teamHeader) {
                                            teamHeader = document.createElement('h2');
                                        teamHeader.innerText = 'Equipo';
                                        area.appendChild(teamHeader);
            } else if (teamHeader.tagName === 'H3') {
                const newH2 = document.createElement('h2');
                                        newH2.innerHTML = teamHeader.innerHTML;
                                        newH2.id = teamHeader.id;
                                        teamHeader.replaceWith(newH2);
                                        teamHeader = newH2;
            }

                                        const grid = document.createElement('div');
                                        grid.id = TEAM_GRID_ID;
                                        grid.className = 'team-grid';
            
            TEAM_DATA.forEach(member => {
                const card = document.createElement('div');
                                        card.className = 'team-card';
                                        const photoUrl = member.foto_url ? (member.foto_url.startsWith('http') ? member.foto_url : '/' + member.foto_url) : 'https://ui-avatars.com/api/?name=' + encodeURIComponent(member.nombre) + '&background=141414&color=5DFFBF&size=512';
                                        card.innerHTML = `
                                        <div class="team-photo-container"><img src="${photoUrl}" alt="${member.nombre}" loading="lazy"></div>
                                        <div class="team-card-info">
                                            <span class="team-role">${member.cargo || 'Equipo Nextica'}</span>
                                            <span class="team-name">${member.nombre}</span>
                                            ${member.descripcion ? `<p class="team-desc">${member.descripcion}</p>` : ''}
                                        </div>`;
                                        grid.appendChild(card);
            });

                                        if (teamHeader && teamHeader.parentElement && teamHeader.tagName === 'H2' && teamHeader.parentElement.tagName !== 'SECTION') {
                                            let current = teamHeader.nextElementSibling;
                                        while (current && (!current.tagName || !['H2', 'H3', 'HR', 'SECTION'].includes(current.tagName))) {
                                            let next = current.nextElementSibling;
                                        current.remove();
                                        current = next;
                }
            } else if (mountPoint) {
                const internalElements = Array.from(mountPoint.children);
                internalElements.forEach(el => {
                    if (el.tagName !== 'H2' && el.tagName !== 'H3') el.remove();
                });
            }

                                        const wrapper = document.getElementById('equipo-extension-area');
                                        if (wrapper) {
                if (mountPoint && mountPoint.id === 'equipo') {
                                            wrapper.appendChild(mountPoint);
                                        mountPoint.appendChild(grid);
                } else {
                                            wrapper.appendChild(teamHeader);
                                        wrapper.appendChild(grid);
                }
            } else {
                if (mountPoint) mountPoint.appendChild(grid);
                                        else if (teamHeader) teamHeader.after(grid);
                                        else area.appendChild(grid);
            }
        }

        /**
         * Construye navegacion jerarquica (H2 padres + H3 hijos).
         * Respeta numeracion "A1.", "A2.3" si existe en el texto.
         */
        function setupNavigation() {
            const nav = document.getElementById('sidebar-nav');
            const mobileContainer = document.getElementById('mobile-nav-container');
            const area = document.getElementById('content-area');
            const extArea = document.getElementById('content-areas-extensions');
            if (!nav) return { sections: [], labels: {} };

            // Recolecta H2 + H3 en orden de documento
            const collect = (root) => root ? Array.from(root.querySelectorAll('h2, h3')) : [];
            const allHeaders = collect(area).concat(collect(extArea));

            nav.innerHTML = '<li class="nav-item"><a href="#top" class="nav-link active" id="nav-intro" data-section="__intro"><span>Inicio</span></a></li>';
            if (mobileContainer) {
                mobileContainer.innerHTML = '<li class="mobile-nav-item"><div class="mobile-nav-row"><a href="#top" class="mobile-nav-link" data-section="__intro" onclick="toggleMobileMenu()"><span>Inicio</span></a></div></li>';
            }

            const sections = [];
            const labels = { __intro: 'Inicio' };
            let currentParent = null;
            let currentChildList = null;
            let currentMobileItem = null;
            let currentMobileChildren = null;

            allHeaders.forEach((el, i) => {
                const raw = (el.innerText || '').trim();
                if (raw.length < 2) return;
                const low = raw.toLowerCase();
                const tag = el.tagName.toLowerCase();

                // Skips: titulo del doc y estados CTA
                if (tag === 'h2' && (low.startsWith('documento funcional') || low.startsWith('documentación funcional') || low.includes('proyecto web'))) {
                    el.style.display = 'none';
                    return;
                }

                // Extrae numeracion tipo "A1.", "2.3", "A2.1" al principio
                const numMatch = raw.match(/^([A-Z]?\d+(?:\.\d+)*\.?)\s+(.+)/);
                const numero = numMatch ? numMatch[1].replace(/\.$/, '') : '';
                const texto = numMatch ? numMatch[2] : raw;

                if (!el.id) {
                    el.id = 'sec-' + (numero ? numero.toLowerCase().replace(/\./g, '-') : i);
                }

                if (tag === 'h2') {
                    sections.push({ id: el.id, el: el, level: 2 });
                    labels[el.id] = texto;

                    const li = document.createElement('li');
                    li.className = 'nav-item';
                    li.dataset.sectionId = el.id;
                    const isCTA = low.includes('avanzamos');
                    const className = isCTA ? 'nav-link-cta active' : 'nav-link';
                    const numHTML = numero ? `<span class="nav-num">${numero}</span>` : '';
                    const caretHTML = '<svg class="nav-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>';
                    li.innerHTML = `<a href="#${el.id}" class="${className}" data-section="${el.id}">${numHTML}<span>${texto}</span>${isCTA ? '' : caretHTML}</a>`;
                    nav.appendChild(li);
                    currentParent = li;

                    currentChildList = document.createElement('ul');
                    currentChildList.className = 'nav-item-children';
                    li.appendChild(currentChildList);

                    if (mobileContainer) {
                        const mNumHTML = numero ? `<span class="mobile-nav-num">${numero}</span>` : '';
                        const mLi = document.createElement('li');
                        mLi.className = 'mobile-nav-item';
                        mLi.dataset.sectionId = el.id;
                        const caretBtn = isCTA ? '' : `<button class="mobile-nav-caret" type="button" aria-label="Desplegar subsecciones" aria-expanded="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg></button>`;
                        mLi.innerHTML = `<div class="mobile-nav-row"><a href="#${el.id}" class="mobile-nav-link" data-section="${el.id}" onclick="toggleMobileMenu()">${mNumHTML}<span>${texto}</span></a>${caretBtn}</div>`;
                        const mChildUl = document.createElement('ul');
                        mChildUl.className = 'mobile-nav-children';
                        mLi.appendChild(mChildUl);
                        mobileContainer.appendChild(mLi);
                        currentMobileItem = mLi;
                        currentMobileChildren = mChildUl;
                    }
                } else if (tag === 'h3' && currentChildList) {
                    // H3 solo si ya hay un H2 padre
                    sections.push({ id: el.id, el: el, level: 3, parentId: currentParent ? currentParent.dataset.sectionId : null });
                    labels[el.id] = texto;
                    const subLi = document.createElement('li');
                    subLi.innerHTML = `<a href="#${el.id}" class="nav-link--sub" data-section="${el.id}"><span>${texto}</span></a>`;
                    currentChildList.appendChild(subLi);

                    if (currentMobileChildren) {
                        const mSubLi = document.createElement('li');
                        mSubLi.innerHTML = `<a href="#${el.id}" class="mobile-nav-sublink" data-section="${el.id}" onclick="toggleMobileMenu()"><span>${texto}</span></a>`;
                        currentMobileChildren.appendChild(mSubLi);
                    }
                }
            });

            // Click en padre H2 abre/cierra hijos (sin impedir la navegacion)
            nav.querySelectorAll('.nav-link .nav-caret').forEach(caret => {
                caret.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    const item = caret.closest('.nav-item');
                    if (item) item.classList.toggle('is-open');
                });
            });

            // Mobile: oculta carets de items sin hijos; toggle desplegable
            if (mobileContainer) {
                mobileContainer.querySelectorAll('.mobile-nav-item').forEach(item => {
                    const children = item.querySelector('.mobile-nav-children');
                    const caret = item.querySelector('.mobile-nav-caret');
                    if (caret && (!children || children.children.length === 0)) {
                        caret.remove();
                        if (children) children.remove();
                    }
                });
                mobileContainer.querySelectorAll('.mobile-nav-caret').forEach(caret => {
                    caret.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        const item = caret.closest('.mobile-nav-item');
                        if (!item) return;
                        const willOpen = !item.classList.contains('is-open');
                        item.classList.toggle('is-open');
                        caret.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                    });
                });
            }

            return { sections, labels };
        }

        function toggleMobileMenu() {
            const menu = document.getElementById('mobileNav');
            const isOpen = menu.classList.contains('open');
            if (isOpen) { menu.classList.remove('open'); document.body.style.overflow = ''; }
            else {
                // Colapsa todas las secciones desplegadas al abrir
                menu.querySelectorAll('.mobile-nav-item.is-open').forEach(item => {
                    item.classList.remove('is-open');
                    const caret = item.querySelector('.mobile-nav-caret');
                    if (caret) caret.setAttribute('aria-expanded', 'false');
                });
                menu.scrollTop = 0;
                menu.classList.add('open');
                document.body.style.overflow = 'hidden';
            }
        }

        /**
         * Activa seccion actual via IntersectionObserver (mas eficiente que scroll+rect).
         */
        function setupScrollSpy(sections, labels) {
            if (!sections.length) return;
            const nav = document.getElementById('sidebar-nav');
            const mobileContainer = document.getElementById('mobile-nav-container');
            const label = document.getElementById('progressLabel');
            const mobileNavLinks = document.querySelectorAll('.mobile-nav-link, .mobile-nav-sublink');
            let currentId = null;

            const applyActive = (id) => {
                if (id === currentId) return;
                currentId = id;

                // Sidebar links
                document.querySelectorAll('.nav-link, .nav-link--sub, .nav-link-cta').forEach(l => {
                    const sec = l.dataset.section;
                    l.classList.toggle('active', sec === id || (!id && l.id === 'nav-intro'));
                });

                // Mobile (incluye sublinks)
                mobileNavLinks.forEach(l => {
                    const sec = l.dataset.section;
                    l.classList.toggle('active', sec === id || (!id && sec === '__intro'));
                });

                // Abre el padre si el activo es un H3
                const section = sections.find(s => s.id === id);
                nav.querySelectorAll('.nav-item.is-open').forEach(li => {
                    if (!section || section.level !== 3 || li.dataset.sectionId !== section.parentId) {
                        li.classList.remove('is-open');
                    }
                });
                if (section && section.level === 3 && section.parentId) {
                    const parent = nav.querySelector(`[data-section-id="${section.parentId}"]`);
                    if (parent) parent.classList.add('is-open');
                }

                // Mobile: abre el padre si el activo es un H3 (sin cerrar si el usuario ya abrió otro)
                if (mobileContainer && section && section.level === 3 && section.parentId) {
                    const mParent = mobileContainer.querySelector(`[data-section-id="${section.parentId}"]`);
                    if (mParent && !mParent.classList.contains('is-open')) {
                        mParent.classList.add('is-open');
                        const mCaret = mParent.querySelector('.mobile-nav-caret');
                        if (mCaret) mCaret.setAttribute('aria-expanded', 'true');
                    }
                }

                // Etiqueta flotante
                if (label) {
                    if (id && labels[id]) {
                        label.textContent = labels[id];
                        label.classList.add('visible');
                    } else {
                        label.classList.remove('visible');
                    }
                }
            };

            // Usa IntersectionObserver con margin que prioriza cabeceras justo entradas al viewport
            const visible = new Map();
            const io = new IntersectionObserver((entries) => {
                entries.forEach(e => {
                    if (e.isIntersecting) visible.set(e.target.id, e.boundingClientRect.top);
                    else visible.delete(e.target.id);
                });
                if (visible.size) {
                    // Elige el que este mas cerca del top (mayor tiempo leyendo)
                    const sorted = [...visible.entries()].sort((a, b) => a[1] - b[1]);
                    applyActive(sorted[0][0]);
                } else if (window.scrollY < 80) {
                    applyActive(null);
                }
            }, { rootMargin: '-15% 0px -70% 0px', threshold: 0 });

            sections.forEach(s => io.observe(s.el));

            // Barra de progreso (sigue usando scroll nativo)
            const fill = document.getElementById('progressFill');
            let ticking = false;
            window.addEventListener('scroll', () => {
                if (ticking) return;
                ticking = true;
                requestAnimationFrame(() => {
                    const h = document.documentElement.scrollHeight - window.innerHeight;
                    if (h > 0 && fill) fill.style.width = ((window.scrollY / h) * 100) + '%';
                    if (window.scrollY < 80) applyActive(null);
                    ticking = false;
                });
            }, { passive: true });
        }

        window.addEventListener('DOMContentLoaded', () => {
            injectTeamSection();
            const { sections, labels } = setupNavigation();
            setupScrollSpy(sections, labels);
            if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
            setupSitemapInteractions();
            setupContentProtection();
            setupThemeToggle();
            setupDocTabs();
            setupOnboarding();
        });

        /**
         * Muestra un coachmark la primera vez que el cliente entra al documento,
         * explicando dónde puede dejar comentarios. Una vez cerrado, se recuerda
         * por propuesta en localStorage.
         */
        function setupOnboarding() {
            const slug = (window.tpSlug || 'doc').toString().replace(/[^a-z0-9-]/gi,'');
            const KEY = 'tp-onb-comentarios-' + slug;
            let done = null;
            try { done = localStorage.getItem(KEY); } catch (e) {}
            if (done === '1') return;

            const onb = document.getElementById('tp-onboarding');
            const fab = document.getElementById('tp-fab');
            if (!onb || !fab) return;

            const close = (persist) => {
                onb.hidden = true;
                if (persist) {
                    try { localStorage.setItem(KEY, '1'); } catch (e) {}
                }
            };

            // Delay para que el FAB ya esté en su sitio
            setTimeout(() => {
                onb.hidden = false;
                if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
            }, 1400);

            document.getElementById('tp-onb-ok')?.addEventListener('click', () => close(true));
            document.getElementById('tp-onb-dismiss')?.addEventListener('click', () => close(true));

            // Si el usuario pulsa directamente el FAB, también damos el onboarding por visto
            fab.addEventListener('click', () => close(true), { once: true });
        }

        /**
         * Tabs documento/presupuesto/firmas.
         * - Alterna visibilidad de los .doc-view[data-tab].
         * - Persiste en sessionStorage.
         * - Hash routing: #presupuesto, #firmas, o cualquier anchor dentro
         *   de una tab (activa la tab padre y hace scroll al elemento).
         */
        function setupDocTabs() {
            const tabs  = Array.from(document.querySelectorAll('.doc-tab[data-tab-target]'));
            const views = Array.from(document.querySelectorAll('.doc-view[data-tab]'));
            if (!tabs.length) return;

            const applyTab = (tab, opts = {}) => {
                if (!tabs.some(t => t.dataset.tabTarget === tab)) tab = 'documento';
                tabs.forEach(t => {
                    const active = t.dataset.tabTarget === tab;
                    t.classList.toggle('is-active', active);
                    t.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                views.forEach(v => {
                    const show = v.dataset.tab === tab;
                    v.hidden = !show;
                });
                document.body.classList.remove('is-tab-documento', 'is-tab-presupuesto', 'is-tab-firmas');
                document.body.classList.add('is-tab-' + tab);
                try { sessionStorage.setItem('tp-doc-tab', tab); } catch (e) {}
                if (!opts.keepScroll) window.scrollTo({ top: 0, behavior: 'instant' in window ? 'instant' : 'auto' });
                // Refresca iconos lucide por si alguna tab estaba hidden al cargar
                if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
            };

            tabs.forEach(t => {
                t.addEventListener('click', () => applyTab(t.dataset.tabTarget));
            });

            // Resolución de tab inicial: hash > sessionStorage > documento
            const resolveFromHash = () => {
                const h = (location.hash || '').replace(/^#/, '');
                if (!h) return null;
                if (h === 'presupuesto' || h === 'firmas' || h === 'documento') return h;
                // Anchor dentro de una vista: detecta qué tab la contiene
                const target = document.getElementById(h);
                if (!target) return null;
                const parentView = target.closest('.doc-view[data-tab]');
                return parentView ? parentView.dataset.tab : null;
            };

            let stored = null;
            try { stored = sessionStorage.getItem('tp-doc-tab'); } catch (e) {}
            const initial = resolveFromHash() || stored || 'documento';
            applyTab(initial, { keepScroll: !!location.hash });

            // Si el hash era un anchor profundo, scroll al elemento tras mostrar la tab
            if (location.hash) {
                const t = document.getElementById(location.hash.slice(1));
                if (t) requestAnimationFrame(() => t.scrollIntoView({ block: 'start' }));
            }

            // Hash change en runtime (links internos entre tabs)
            window.addEventListener('hashchange', () => {
                const resolved = resolveFromHash();
                if (resolved) {
                    applyTab(resolved, { keepScroll: true });
                    const t = document.getElementById(location.hash.slice(1));
                    if (t) requestAnimationFrame(() => t.scrollIntoView({ block: 'start' }));
                }
            });
        }

        /**
         * Theme toggle: alterna light/dark y persiste en localStorage.
         * El init inline del <head> ya aplica el tema antes del render.
         */
        function setupThemeToggle() {
            const btn = document.getElementById('themeToggle');
            if (!btn) return;
            btn.addEventListener('click', () => {
                const current = document.documentElement.getAttribute('data-theme') || 'dark';
                const next = current === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', next);
                try { localStorage.setItem('tp-theme', next); } catch(e) {}
                if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
            });
        }

        /**
         * Protección de contenido: bloquea copia, menú contextual, arrastre
         * y atajos de teclado habituales de extracción. Los inputs mantienen
         * selección de texto natural.
         */
        function setupContentProtection() {
            const isEditable = (t) => t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable);
            ['copy', 'cut', 'contextmenu', 'selectstart', 'dragstart'].forEach(evt => {
                document.addEventListener(evt, (e) => {
                    if (isEditable(e.target)) return;
                    e.preventDefault();
                });
            });
            document.addEventListener('keydown', (e) => {
                if (isEditable(e.target)) return;
                const mod = e.ctrlKey || e.metaKey;
                const k = (e.key || '').toLowerCase();
                if (mod && ['c', 'x', 'a', 's', 'p', 'u'].includes(k)) {
                    e.preventDefault();
                    return;
                }
                if (e.key === 'F12') e.preventDefault();
                if (mod && e.shiftKey && ['i', 'j', 'c'].includes(k)) e.preventDefault();
            });
        }

        /**
         * Busqueda y "Expandir/Colapsar todo" en todos los .tp-sitemap del documento.
         */
        function setupSitemapInteractions() {
            document.querySelectorAll('.tp-sitemap').forEach(sm => {
                const search = sm.querySelector('.tp-sitemap__search');
                const toggle = sm.querySelector('.tp-sitemap__toggle');
                const groups = sm.querySelectorAll('.tp-sitemap__group');

                if (search) {
                    search.addEventListener('input', (e) => {
                        const q = e.target.value.trim().toLowerCase();
                        groups.forEach(g => {
                            const nodes = g.querySelectorAll('.tp-sitemap__node');
                            let anyMatch = false;
                            nodes.forEach(n => {
                                const txt = n.textContent.toLowerCase();
                                const match = !q || txt.includes(q);
                                n.classList.toggle('is-filtered-out', !match);
                                if (match) anyMatch = true;
                            });
                            g.classList.toggle('is-filtered-out', !anyMatch);
                            if (q && anyMatch) g.open = true;
                        });
                    });
                }

                if (toggle) {
                    toggle.addEventListener('click', () => {
                        const anyClosed = Array.from(groups).some(g => !g.open);
                        groups.forEach(g => g.open = anyClosed);
                        toggle.textContent = anyClosed ? 'Colapsar todo' : 'Expandir todo';
                    });
                }
            });
        }

        function openModal(type) { document.getElementById('modal-' + type).classList.add('open'); }
        function closeModal(type) { document.getElementById('modal-' + type).classList.remove('open'); }
        document.querySelectorAll('.modal-overlay').forEach(m => {
            m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
        });

        async function apiCall(action, params = {}) {
            const formData = new URLSearchParams();
            formData.append('api_action', action);
            for (let k in params) formData.append(k, params[k]);
            return fetch(window.location.href, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: formData
            });
        }

        function readSigner(prefix) {
            const n = (document.getElementById('sign-' + prefix + '-nombre').value || '').trim();
            const a = (document.getElementById('sign-' + prefix + '-apellidos').value || '').trim();
            const e = (document.getElementById('sign-' + prefix + '-email').value || '').trim();
            if (!n || !a) { alert('Nombre y apellidos son obligatorios para firmar.'); return null; }
            try { localStorage.setItem('tp_signer', JSON.stringify({nombre: n, apellidos: a, email: e})); } catch(_){}
            return { firmante_nombre: n, firmante_apellidos: a, firmante_email: e };
        }

        (function prefillSigner(){
            try {
                const s = JSON.parse(localStorage.getItem('tp_signer') || 'null');
                if (!s) return;
                ['doc','pdf'].forEach(p => {
                    const n = document.getElementById('sign-'+p+'-nombre');
                    const a = document.getElementById('sign-'+p+'-apellidos');
                    const e = document.getElementById('sign-'+p+'-email');
                    if (n && !n.value) n.value = s.nombre || '';
                    if (a && !a.value) a.value = s.apellidos || '';
                    if (e && !e.value) e.value = s.email || '';
                });
            } catch(_){}
        })();

        async function submitApproval() {
            const signer = readSigner('doc'); if (!signer) return;
            const btn = document.querySelector('#approve-form .btn-modal-primary');
            btn.disabled = true; btn.textContent = 'Firmando...';
            const res = await apiCall('approve_doc', signer);
            const data = await res.json().catch(() => ({}));
            if (!data.success) { alert(data.error || 'Error al firmar'); btn.disabled = false; btn.innerHTML = '<i data-lucide="pen-tool" style="width:16px;height:16px;"></i> Firmar y aprobar'; if(window.lucide) lucide.createIcons(); return; }
            setTimeout(() => window.location.reload(), 800);
        }

        async function submitComment() {
            const text = document.getElementById('comment-text').value.trim();
            if (!text) { document.getElementById('comment-text').focus(); return; }
            const btn = document.querySelector('#comment-form .btn-modal-primary');
            btn.disabled = true; btn.textContent = 'Enviando...';
            await apiCall('comment_doc', { comment: text });
            document.getElementById('comment-form').style.display = 'none';
            document.getElementById('comment-success').style.display = 'block';
        }

        async function submitPdfApproval() {
            const signer = readSigner('pdf'); if (!signer) return;
            const btn = document.querySelector('#approve-pdf-form .btn-modal-primary');
            btn.disabled = true; btn.textContent = 'Firmando...';
            const res = await apiCall('approve_pdf', signer);
            const data = await res.json().catch(() => ({}));
            if (!data.success) { alert(data.error || 'Error al firmar'); btn.disabled = false; btn.innerHTML = '<i data-lucide="pen-tool" style="width:16px;height:16px;"></i> Firmar y aprobar'; if(window.lucide) lucide.createIcons(); return; }
            setTimeout(() => window.location.reload(), 800);
        }

        async function submitPdfRejection() {
            const text = document.getElementById('reject-pdf-text').value.trim();
            if (!text) { document.getElementById('reject-pdf-text').focus(); return; }
            const btn = document.querySelector('#reject-pdf-form .btn-modal-primary');
            btn.disabled = true; btn.textContent = 'Enviando...';
            await apiCall('reject_pdf', { comment: text });
            document.getElementById('reject-pdf-form').style.display = 'none';
            document.getElementById('reject-pdf-success').style.display = 'block';
        }
    </script>

    <!-- Calendly Widget -->
    <link href="https://assets.calendly.com/assets/external/widget.css" rel="stylesheet">
    <script src="https://assets.calendly.com/assets/external/widget.js" type="text/javascript" async></script>

    <!-- Feature: comentarios por sección + firma ligera -->
    <?php if (!$isProviderMode) include __DIR__ . '/master/doc-tracking.php'; ?>
    <?php if ($isAdminMode): ?>
        <script>window.__isAdminViewing = true;</script>
    <?php endif; ?>
    <?php if ($isProviderMode): ?>
        <script>window.__providerApiUrl = <?= json_encode('/s/' . $__provider['token']) ?>;</script>
        <?php include __DIR__ . '/master/doc-feedback-provider.php'; ?>
    <?php else: ?>
        <?php include __DIR__ . '/master/doc-feedback.php'; ?>
        <?php include __DIR__ . '/master/doc-tasks.php'; ?>
        <?php include __DIR__ . '/master/doc-respuestas.php'; ?>
    <?php endif; ?>

    <!-- Onboarding primera visita: apunta al FAB de comentarios -->
    <div class="tp-onboarding" id="tp-onboarding" role="dialog" aria-labelledby="tp-onb-title" hidden>
        <button class="tp-onboarding__dismiss" type="button" id="tp-onb-dismiss" aria-label="Cerrar">
            <i data-lucide="x"></i>
        </button>
        <div class="tp-onboarding__title" id="tp-onb-title">
            <i data-lucide="message-square-text"></i>
            <span>Tus comentarios son bienvenidos</span>
        </div>
        <div class="tp-onboarding__body">
            Si quieres dejar dudas, cambios o ideas sobre cualquier parte del documento, pulsa
            <strong>el icono de mensaje</strong> junto al título de cada sección — o el botón
            <strong>Comentarios</strong> de abajo a la derecha. Los leemos al instante.
        </div>
        <button class="tp-onboarding__ok" type="button" id="tp-onb-ok">Entendido</button>
    </div>
    <script>window.tpSlug = <?php echo json_encode($slug); ?>;</script>

    <!-- Jordan-doc: agente conversacional (Haiku) — solo si habilitado global y por propuesta -->
    <?php
    $jordanAllowed = defined('JORDAN_DOC_ENABLED') && JORDAN_DOC_ENABLED
        && (!isset($proposal['enable_ai_assistant']) || (int)$proposal['enable_ai_assistant'] === 1);
    if ($jordanAllowed && !$isProviderMode) {
        include __DIR__ . '/master/jordan-widget.php';
    }
    ?>

    <!-- ============================================================
         Onboarding TOUR de primera visita (multi-paso, spotlight)
         Bloque autocontenido y aditivo. Resalta la pestaña «Presupuesto»
         para que el cliente no se la pierda. Solo se muestra UNA vez por
         documento (localStorage) y solo en la vista CLIENTE normal
         (NO admin, NO proveedor). Falla en silencio ante cualquier error.
         ============================================================ -->
    <style>
        .tpob-overlay {
            position: fixed; inset: 0; z-index: 4000;
            background: rgba(0, 0, 0, .62);
            opacity: 0; transition: opacity .25s ease;
            pointer-events: auto;
        }
        [data-theme="light"] .tpob-overlay { background: rgba(20, 20, 22, .5); }
        .tpob-overlay.tpob-show { opacity: 1; }
        /* Recorte (spotlight) alrededor del elemento resaltado mediante box-shadow gigante */
        .tpob-hole {
            position: fixed; z-index: 4001;
            border-radius: 12px;
            box-shadow: 0 0 0 9999px rgba(0, 0, 0, .62), 0 0 0 3px var(--tp-primary, #5dffbf);
            outline: 2px solid var(--tp-primary, #5dffbf);
            outline-offset: 3px;
            pointer-events: none;
            transition: all .28s cubic-bezier(.16, 1, .3, 1);
        }
        [data-theme="light"] .tpob-hole { box-shadow: 0 0 0 9999px rgba(20, 20, 22, .5), 0 0 0 3px var(--tp-primary, #5dffbf); }
        .tpob-tip {
            position: fixed; z-index: 4002;
            max-width: 320px; width: calc(100vw - 2rem);
            background: var(--bg-surface, #16181d);
            border: 1px solid var(--border-strong, rgba(255,255,255,.18));
            border-radius: 14px;
            padding: 1.15rem 1.25rem 1.1rem;
            box-shadow: 0 20px 50px rgba(0, 0, 0, .5), 0 2px 6px rgba(0, 0, 0, .3);
            color: var(--text-primary, #f5f5f5);
            opacity: 0; transform: translateY(8px);
            transition: opacity .25s ease, transform .25s ease;
        }
        [data-theme="light"] .tpob-tip { box-shadow: 0 20px 50px rgba(20,20,20,.18), 0 2px 6px rgba(20,20,20,.08); }
        .tpob-tip.tpob-show { opacity: 1; transform: translateY(0); }
        .tpob-tip__close {
            position: absolute; top: .55rem; right: .55rem;
            width: 28px; height: 28px;
            display: inline-flex; align-items: center; justify-content: center;
            border: 0; background: transparent; cursor: pointer;
            color: var(--text-muted, #9aa0a6); border-radius: 6px;
            transition: background .15s ease, color .15s ease;
        }
        .tpob-tip__close:hover { background: var(--bg-nav-hover, rgba(255,255,255,.08)); color: var(--text-primary, #fff); }
        .tpob-tip__close i { width: 15px; height: 15px; }
        .tpob-tip__title {
            display: flex; align-items: center; gap: .55rem;
            font-family: var(--font-heading, inherit);
            font-weight: 700; font-size: 1rem;
            margin: 0 1.5rem .55rem 0;
            color: var(--text-primary, #f5f5f5);
        }
        .tpob-tip__title i { width: 20px; height: 20px; color: var(--tp-primary, #5dffbf); flex: 0 0 auto; }
        .tpob-tip__body {
            font-size: .85rem; line-height: 1.55;
            color: var(--text-secondary, #c4c8ce); margin-bottom: 1rem;
        }
        .tpob-tip__body strong { color: var(--text-primary, #fff); font-weight: 600; }
        .tpob-tip__foot { display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
        .tpob-tip__steps { font-size: .72rem; color: var(--text-muted, #9aa0a6); letter-spacing: .02em; }
        .tpob-tip__actions { display: inline-flex; gap: .5rem; }
        .tpob-btn {
            display: inline-flex; align-items: center; justify-content: center;
            padding: .5rem .9rem; border-radius: 8px; cursor: pointer;
            font-family: var(--font-heading, inherit); font-weight: 700; font-size: .8rem;
            border: 1px solid transparent; transition: filter .15s ease, background .15s ease, color .15s ease;
        }
        .tpob-btn--skip {
            background: transparent; border-color: var(--border-strong, rgba(255,255,255,.18));
            color: var(--text-secondary, #c4c8ce);
        }
        .tpob-btn--skip:hover { color: var(--text-primary, #fff); background: var(--bg-nav-hover, rgba(255,255,255,.06)); }
        .tpob-btn--next { background: var(--tp-primary, #5dffbf); color: #0e0e0e; border: 0; }
        [data-theme="light"] .tpob-btn--next { color: #ffffff; }
        .tpob-btn--next:hover { filter: brightness(1.08); }
        .tpob-tip__arrow {
            position: absolute; width: 14px; height: 14px;
            background: var(--bg-surface, #16181d);
            border: 1px solid var(--border-strong, rgba(255,255,255,.18));
            transform: rotate(45deg);
        }
        @media (max-width: 600px) {
            .tpob-tip { max-width: none; }
        }
        @media (prefers-reduced-motion: reduce) {
            .tpob-overlay, .tpob-hole, .tpob-tip { transition: none; }
        }
    </style>
    <script>
    (function () {
        try {
            // --- Gating: solo vista CLIENTE normal -----------------------------
            if (window.__isAdminViewing === true) return;          // staff/admin
            if (typeof window.__providerApiUrl !== 'undefined') return; // modo proveedor

            var slug = (window.tpSlug || 'doc').toString().replace(/[^a-z0-9-]/gi, '');
            var KEY  = 'tp_onboarding_done_' + slug;

            // Ya visto -> no repetir
            var done = null;
            try { done = localStorage.getItem(KEY); } catch (e) {}
            if (done === '1') return;

            // --- Feature-detection: sin pestaña «Presupuesto» no hay tour ------
            var presupuestoTab = document.querySelector('.doc-tab[data-tab-target="presupuesto"]');
            if (!presupuestoTab) return;

            // Evitar que el coachmark antiguo (FAB comentarios) salga a la vez:
            // pre-marcamos su clave como vista (no editamos su código).
            try { localStorage.setItem('tp-onb-comentarios-' + slug, '1'); } catch (e) {}

            // --- Construir la lista de pasos (solo los que existen) ------------
            var steps = [];
            steps.push({
                el: presupuestoTab,
                icon: 'file-spreadsheet',
                title: 'Aquí tienes el presupuesto',
                body: 'En esta pestaña <strong>Presupuesto</strong> encontrarás el detalle económico del proyecto. Revísalo con calma y, si encaja, dale el OK.',
                onShow: null
            });

            var fab = document.getElementById('tp-fab');
            if (fab) {
                steps.push({
                    el: fab,
                    icon: 'message-square-text',
                    title: 'Comenta lo que quieras',
                    body: 'Puedes dejar dudas, cambios o ideas sobre <strong>cualquier sección</strong> del documento. Las leemos al instante.',
                    onShow: null
                });
            }

            // Botón de aprobar presupuesto (vive dentro de la pestaña Presupuesto).
            // Como esa vista puede estar oculta, lo activamos al llegar al paso.
            var approveBtn = document.querySelector('button.btn-cta-primary[onclick*="approve-pdf"]');
            if (approveBtn) {
                steps.push({
                    el: approveBtn,
                    icon: 'check-circle',
                    title: 'Cuando estéis conformes',
                    body: 'Desde aquí <strong>aprobáis el presupuesto</strong> y arrancamos. Sin prisa: primero revisad todo lo que necesitéis.',
                    onShow: function () {
                        // Asegurarse de que la pestaña Presupuesto está activa para que el botón sea visible
                        try { if (presupuestoTab && !presupuestoTab.classList.contains('is-active')) presupuestoTab.click(); } catch (e) {}
                    }
                });
            }

            if (!steps.length) return;

            // --- Crear DOM del tour -------------------------------------------
            var overlay = document.createElement('div');
            overlay.className = 'tpob-overlay';

            var hole = document.createElement('div');
            hole.className = 'tpob-hole';

            var tip = document.createElement('div');
            tip.className = 'tpob-tip';
            tip.setAttribute('role', 'dialog');
            tip.setAttribute('aria-modal', 'true');
            tip.setAttribute('aria-live', 'polite');

            var arrow = document.createElement('div');
            arrow.className = 'tpob-tip__arrow';

            tip.innerHTML =
                '<button class="tpob-tip__close" type="button" aria-label="Cerrar"><i data-lucide="x"></i></button>' +
                '<div class="tpob-tip__title"><i data-lucide="info"></i><span class="tpob-tip__title-text"></span></div>' +
                '<div class="tpob-tip__body"></div>' +
                '<div class="tpob-tip__foot">' +
                    '<span class="tpob-tip__steps"></span>' +
                    '<span class="tpob-tip__actions">' +
                        '<button class="tpob-btn tpob-btn--skip" type="button"></button>' +
                        '<button class="tpob-btn tpob-btn--next" type="button"></button>' +
                    '</span>' +
                '</div>';
            tip.appendChild(arrow);

            var iconEl  = tip.querySelector('.tpob-tip__title i');
            var titleEl = tip.querySelector('.tpob-tip__title-text');
            var bodyEl  = tip.querySelector('.tpob-tip__body');
            var stepsEl = tip.querySelector('.tpob-tip__steps');
            var skipBtn = tip.querySelector('.tpob-btn--skip');
            var nextBtn = tip.querySelector('.tpob-btn--next');
            var closeBtn = tip.querySelector('.tpob-tip__close');

            var current = 0;
            var mounted = false;

            function refreshIcons() {
                try { if (window.lucide && window.lucide.createIcons) window.lucide.createIcons(); } catch (e) {}
            }

            function persistDone() {
                try { localStorage.setItem(KEY, '1'); } catch (e) {}
            }

            function teardown(persist) {
                if (persist) persistDone();
                document.removeEventListener('keydown', onKey, true);
                window.removeEventListener('resize', reposition);
                window.removeEventListener('scroll', reposition, true);
                [overlay, hole, tip].forEach(function (n) {
                    if (n && n.parentNode) n.parentNode.removeChild(n);
                });
                mounted = false;
            }

            function positionFor(el) {
                var r = el.getBoundingClientRect();
                var pad = 6;
                // Spotlight
                hole.style.top    = (r.top - pad) + 'px';
                hole.style.left   = (r.left - pad) + 'px';
                hole.style.width  = (r.width + pad * 2) + 'px';
                hole.style.height = (r.height + pad * 2) + 'px';

                // Tooltip: debajo si cabe, si no encima
                var tipRect = tip.getBoundingClientRect();
                var tipW = tipRect.width || 320;
                var tipH = tipRect.height || 160;
                var gap = 14;
                var vw = window.innerWidth, vh = window.innerHeight;

                var placeBelow = (r.bottom + gap + tipH) <= vh;
                var top, left;
                if (placeBelow) {
                    top = r.bottom + gap;
                } else {
                    top = r.top - gap - tipH;
                    if (top < 8) top = Math.max(8, (vh - tipH) / 2);
                }
                // Centrar horizontalmente sobre el elemento, con clamp a viewport
                left = r.left + (r.width / 2) - (tipW / 2);
                if (left < 8) left = 8;
                if (left + tipW > vw - 8) left = vw - 8 - tipW;
                tip.style.top = Math.max(8, top) + 'px';
                tip.style.left = left + 'px';

                // Flecha apuntando al elemento
                var arrowLeft = (r.left + r.width / 2) - left - 7;
                arrowLeft = Math.max(12, Math.min(tipW - 26, arrowLeft));
                arrow.style.left = arrowLeft + 'px';
                if (placeBelow) {
                    arrow.style.top = '-7px';
                    arrow.style.bottom = '';
                    arrow.style.borderRight = 'none';
                    arrow.style.borderBottom = 'none';
                } else {
                    arrow.style.bottom = '-7px';
                    arrow.style.top = '';
                    arrow.style.borderLeft = 'none';
                    arrow.style.borderTop = 'none';
                }
            }

            function reposition() {
                try {
                    var step = steps[current];
                    if (step && step.el) positionFor(step.el);
                } catch (e) {}
            }

            function render() {
                var step = steps[current];
                if (!step || !step.el) { teardown(true); return; }
                if (typeof step.onShow === 'function') { try { step.onShow(); } catch (e) {} }

                iconEl.setAttribute('data-lucide', step.icon || 'info');
                titleEl.textContent = step.title || '';
                bodyEl.innerHTML = step.body || '';
                stepsEl.textContent = (steps.length > 1) ? (current + 1) + ' / ' + steps.length : '';
                var last = current === steps.length - 1;
                nextBtn.textContent = last ? 'Entendido' : 'Siguiente';
                skipBtn.textContent = 'Saltar';
                skipBtn.style.display = last ? 'none' : '';
                refreshIcons();

                // Reposicionar tras pintar (dos rAF para medir el tooltip ya renderizado)
                requestAnimationFrame(function () {
                    reposition();
                    requestAnimationFrame(reposition);
                });
                // Llevar el elemento a la vista si está fuera de pantalla
                try {
                    var rr = step.el.getBoundingClientRect();
                    if (rr.top < 0 || rr.bottom > window.innerHeight) {
                        step.el.scrollIntoView({ block: 'center', behavior: 'smooth' });
                        setTimeout(reposition, 350);
                    }
                } catch (e) {}
                nextBtn.focus();
            }

            function nextStep() {
                if (current < steps.length - 1) { current++; render(); }
                else teardown(true);
            }

            function onKey(e) {
                if (e.key === 'Escape') { e.preventDefault(); teardown(true); }
                else if (e.key === 'Enter') { e.preventDefault(); nextStep(); }
            }

            // --- Mostrar --------------------------------------------------------
            function mount() {
                if (mounted) return;
                document.body.appendChild(overlay);
                document.body.appendChild(hole);
                document.body.appendChild(tip);
                mounted = true;

                nextBtn.addEventListener('click', nextStep);
                skipBtn.addEventListener('click', function () { teardown(true); });
                closeBtn.addEventListener('click', function () { teardown(true); });
                overlay.addEventListener('click', function () { teardown(true); });
                document.addEventListener('keydown', onKey, true);
                window.addEventListener('resize', reposition);
                window.addEventListener('scroll', reposition, true);

                render();
                requestAnimationFrame(function () {
                    overlay.classList.add('tpob-show');
                    tip.classList.add('tpob-show');
                });
            }

            // Pequeño delay para que layout/iconos estén asentados
            var startTour = function () { try { mount(); } catch (e) {} };
            if (document.readyState === 'loading') {
                window.addEventListener('DOMContentLoaded', function () { setTimeout(startTour, 1100); });
            } else {
                setTimeout(startTour, 1100);
            }
        } catch (e) {
            /* Falla en silencio: nunca debe romper el visor */
        }
    })();
    </script>
</body>

</html>
<?php
}
?>