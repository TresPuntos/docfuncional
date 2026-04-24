<?php
/**
 * Admin · Gestión de proveedores por propuesta
 *
 * URL: /admin_providers.php?propuesta_id=X
 *
 * - Invitar proveedor (genera token + PIN, opcional email via Resend)
 * - Listar proveedores invitados con estado de acceso
 * - Ver presupuestos recibidos (descargar PDF, comparar)
 * - Ver mensajes del proveedor + responder como staff
 * - Revocar acceso
 */

require __DIR__ . '/config.php';
session_start();

// Auth (misma ADMIN_PASSWORD que el resto del admin)
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
        if ($_POST['admin_password'] === ADMIN_PASSWORD) {
            $_SESSION['admin_logged'] = true;
            $redir = 'admin_providers.php' . (isset($_GET['propuesta_id']) ? '?propuesta_id=' . (int)$_GET['propuesta_id'] : '');
            header('Location: ' . $redir); exit;
        }
    }
    ?>
    <!doctype html><meta charset="utf-8"><title>Admin · Providers</title>
    <style>body{background:#0e0e0e;color:#f5f5f5;font-family:system-ui;display:grid;place-items:center;height:100vh;margin:0}form{background:#141414;padding:2rem;border-radius:12px;border:1px solid #1f1f1f;display:grid;gap:.75rem;width:320px}input{background:#191919;border:1px solid #1f1f1f;color:#fff;padding:.6rem;border-radius:6px}button{background:#5dffbf;color:#000;border:none;padding:.6rem;border-radius:6px;font-weight:700;cursor:pointer}</style>
    <form method="post"><strong>Admin Providers</strong><input name="admin_password" type="password" placeholder="Contraseña" autofocus><button>Entrar</button></form>
    <?php exit;
}

$pdo = getDBConnection();

// ---- Helpers globales (una sola declaración en todo el archivo) ----
if (!function_exists('e')) {
    function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('fecha')) {
    function fecha($d){ return $d ? date('d/m/Y H:i', strtotime($d)) : '—'; }
}

// ---- Download de archivo presupuesto ----
if (isset($_GET['download']) && is_numeric($_GET['download'])) {
    $id = (int)$_GET['download'];
    $row = $pdo->prepare("SELECT pr.archivo_path, pr.archivo_nombre, pr.archivo_mime FROM proveedor_presupuestos pr WHERE pr.id = ?");
    $row->execute([$id]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if (!$r) { http_response_code(404); echo 'No existe'; exit; }
    $path = __DIR__ . '/' . $r['archivo_path'];
    if (!file_exists($path)) { http_response_code(404); echo 'Archivo no encontrado'; exit; }
    header('Content-Type: ' . ($r['archivo_mime'] ?: 'application/pdf'));
    header('Content-Disposition: inline; filename="' . $r['archivo_nombre'] . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ---- POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    if ($action === 'invite_provider') {
        $propId = (int)($_POST['propuesta_id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $empresa = trim($_POST['empresa'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $verComentarios = isset($_POST['ver_comentarios']) ? 1 : 0;
        $sendEmail = isset($_POST['send_email']);

        if (!$propId || !$nombre || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Datos inválidos']);
            exit;
        }

        $token = bin2hex(random_bytes(16)); // 32 chars
        $pin = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        $pdo->prepare("INSERT INTO propuesta_proveedores (propuesta_id, nombre, empresa, email, token, pin, ver_comentarios)
                       VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$propId, $nombre, $empresa ?: null, $email, $token, $pin, $verComentarios]);
        $provId = (int)$pdo->lastInsertId();

        $host = $_SERVER['HTTP_HOST'] ?? 'doc.trespuntos-lab.com';
        $scheme = (($_SERVER['HTTPS'] ?? '') === 'on' || strpos($host, 'localhost') === false) ? 'https' : 'http';
        $url = $scheme . '://' . $host . '/s/' . $token;

        // Email opcional
        $emailSent = false;
        $emailError = null;
        if ($sendEmail && defined('RESEND_API_KEY') && RESEND_API_KEY) {
            $propQ = $pdo->prepare("SELECT client_name FROM propuestas WHERE id = ?");
            $propQ->execute([$propId]);
            $clientName = $propQ->fetchColumn() ?: 'cliente';
            [$emailSent, $emailError] = sendProviderInviteEmail($nombre, $empresa, $email, $clientName, $url, $pin);
        }

        echo json_encode([
            'success' => true,
            'provider_id' => $provId,
            'token' => $token,
            'pin' => $pin,
            'url' => $url,
            'email_sent' => $emailSent,
            'email_error' => $emailError,
        ]);
        exit;
    }

    if ($action === 'revoke_provider') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE propuesta_proveedores SET activo = 0 WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'reactivate_provider') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE propuesta_proveedores SET activo = 1 WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete_provider') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'error' => 'ID inválido']); exit; }

        // Verificar que existe y obtener propuesta_id para el path de archivos
        $chk = $pdo->prepare("SELECT id, propuesta_id, nombre FROM propuesta_proveedores WHERE id = ?");
        $chk->execute([$id]);
        $prov = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$prov) { echo json_encode(['success' => false, 'error' => 'Proveedor no existe']); exit; }

        try {
            $pdo->beginTransaction();

            // 1) Borrar archivos PDF físicos de presupuestos del proveedor
            $pdfs = $pdo->prepare("SELECT archivo_path FROM proveedor_presupuestos WHERE proveedor_id = ?");
            $pdfs->execute([$id]);
            $filesDeleted = 0; $filesFailed = 0;
            while ($row = $pdfs->fetch(PDO::FETCH_ASSOC)) {
                $path = $row['archivo_path'] ?? '';
                if (!$path) continue;
                // Normalizar: puede venir como "uploads/proveedores/X/y.pdf" relativo
                $abs = __DIR__ . '/' . ltrim($path, '/');
                if (is_file($abs)) {
                    if (@unlink($abs)) $filesDeleted++; else $filesFailed++;
                }
            }

            // 2) Borrar presupuestos del proveedor
            $pdo->prepare("DELETE FROM proveedor_presupuestos WHERE proveedor_id = ?")->execute([$id]);

            // 3) Borrar mensajes del proveedor (y replies staff dirigidos a él via parent_id están en la misma tabla, mismo proveedor_id)
            $pdo->prepare("DELETE FROM proveedor_mensajes WHERE proveedor_id = ?")->execute([$id]);

            // 4) Borrar fila del proveedor
            $pdo->prepare("DELETE FROM propuesta_proveedores WHERE id = ?")->execute([$id]);

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'files_deleted' => $filesDeleted,
                'files_failed' => $filesFailed,
                'nombre' => $prov['nombre'],
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Error al eliminar: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'reply_to_provider_msg') {
        $id = (int)($_POST['parent_id'] ?? 0);
        $texto = trim($_POST['texto'] ?? '');
        if (!$id || $texto === '') { echo json_encode(['success' => false, 'error' => 'Datos inválidos']); exit; }
        $parent = $pdo->prepare("SELECT proveedor_id, section_anchor, section_title FROM proveedor_mensajes WHERE id = ?");
        $parent->execute([$id]);
        $p = $parent->fetch(PDO::FETCH_ASSOC);
        if (!$p) { echo json_encode(['success' => false, 'error' => 'No existe']); exit; }
        $pdo->prepare("INSERT INTO proveedor_mensajes (proveedor_id, section_anchor, section_title, autor_tipo, autor_nombre, texto, parent_id)
                       VALUES (?, ?, ?, 'staff', 'Tres Puntos', ?, ?)")
            ->execute([$p['proveedor_id'], $p['section_anchor'], $p['section_title'], $texto, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Acción no reconocida']);
    exit;
}

function sendProviderInviteEmail(string $nombre, string $empresa, string $email, string $clientName, string $url, string $pin): array {
    if (!defined('RESEND_API_KEY') || !RESEND_API_KEY) return [false, 'Resend no configurado'];
    $firstName = htmlspecialchars(strtok($nombre, ' '), ENT_QUOTES);
    $clientNameSafe = htmlspecialchars($clientName, ENT_QUOTES);
    $urlSafe = htmlspecialchars($url, ENT_QUOTES);
    $pinSafe = htmlspecialchars($pin, ENT_QUOTES);

    $htmlBody = <<<HTML
<!doctype html><html><head><meta charset="utf-8"><meta name="color-scheme" content="light only"></head>
<body style="font-family:-apple-system,Inter,sans-serif;background:#f5f5f5;margin:0;padding:24px;color:#0e0e0e;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f5f5f5;">
<tr><td align="center">
<table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;background:#fff;border-radius:12px;border:1px solid #e5e5e5;">
<tr><td align="center" style="padding:32px 32px 8px;">
<img src="https://doc.trespuntos-lab.com/logo-trespuntos.svg" alt="Tres Puntos" width="120" height="46" style="display:block;width:120px;height:auto;border:0;">
</td></tr>
<tr><td style="padding:18px 32px 8px;">
<h1 style="font-size:22px;font-weight:700;margin:0 0 14px;color:#0e0e0e;">Hola {$firstName},</h1>
<p style="font-size:15px;line-height:1.6;color:#333;margin:0 0 16px;">
Te invitamos a presupuestar el proyecto de <strong>{$clientNameSafe}</strong>. Desde el siguiente enlace podrás revisar el documento funcional completo y subirnos tu propuesta en PDF junto con el importe y el plazo.
</p>
<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:18px 0;background:#f5f5f5;border-radius:8px;padding:.5rem;">
<tr><td style="padding:12px 16px;">
<div style="font-size:.72rem;color:#888;text-transform:uppercase;letter-spacing:.05em;font-weight:700;margin-bottom:6px;">Acceso privado</div>
<div style="font-family:monospace;font-size:13px;word-break:break-all;color:#0e0e0e;margin-bottom:8px;">{$urlSafe}</div>
<div style="font-size:.75rem;color:#888;">PIN: <strong style="font-family:monospace;font-size:16px;color:#0e0e0e;letter-spacing:.2em;">{$pinSafe}</strong></div>
</td></tr></table>
</td></tr>
<tr><td align="center" style="padding:8px 32px 28px;">
<a href="{$urlSafe}" style="background:#0e0e0e;color:#5dffbf !important;padding:14px 30px;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;display:inline-block;">Acceder al proyecto →</a>
</td></tr>
<tr><td style="padding:0 32px 24px;">
<p style="font-size:13px;color:#888;line-height:1.55;margin:0;">
Cualquier duda sobre el alcance, déjala como mensaje dentro del portal y te responderemos allí mismo.
</p>
</td></tr>
</table>
<table cellpadding="0" cellspacing="0" border="0" role="presentation" style="margin:24px auto 0;background:#ffffff;">
<tr><td valign="top" style="width:190px;padding:0 12px 0 0;border-right:3px solid #5DFFBF;">
<img src="http://trespuntoscomunicacion.es//img_firma/new-logo.jpg" alt="Tres Puntos" style="display:block;max-width:180px;height:auto;border:0;">
</td><td style="padding:0 0 0 12px;">
<table cellpadding="0" cellspacing="0" border="0" role="presentation" style="margin:0;padding:0;">
<tr><td style="padding-bottom:5px;color:#5DFFBF;font-size:18px;font-weight:800;font-family:Oswald,Helvetica,sans-serif;">Tres Puntos | Agencia UX/UI y Arquitectura web</td></tr>
<tr><td style="padding-bottom:5px;color:#2A2A2A;font-size:16px;font-family:Oswald,Helvetica,sans-serif;">Jordan | Asistente IA · Tres Puntos</td></tr>
<tr><td style="padding-bottom:5px;color:#2A2A2A;font-size:16px;font-family:Oswald,Helvetica,sans-serif;">Responde a este email y le llegará a <a href="mailto:jordi@trespuntoscomunicacion.es" style="color:#2A2A2A;text-decoration:none;">Jordi</a></td></tr>
</table></td></tr></table>
</td></tr></table>
</body></html>
HTML;

    $payload = [
        'from' => defined('RESEND_FROM') ? RESEND_FROM : 'Tres Puntos <onboarding@resend.dev>',
        'to' => [$email],
        'subject' => 'Te invitamos a presupuestar · ' . $clientName,
        'html' => $htmlBody,
        'text' => "Hola $nombre,\n\nTe invitamos a presupuestar el proyecto de $clientName.\n\nAcceso: $url\nPIN: $pin\n\nUn saludo,\nJordan · Tres Puntos",
    ];
    if (defined('RESEND_REPLY_TO') && RESEND_REPLY_TO) $payload['reply_to'] = [RESEND_REPLY_TO];
    if (defined('CLIENT_NOTIFY_CC') && CLIENT_NOTIFY_CC && strcasecmp(CLIENT_NOTIFY_CC, $email) !== 0) {
        $payload['cc'] = [CLIENT_NOTIFY_CC];
    }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . RESEND_API_KEY, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300) return [true, null];
    return [false, 'HTTP ' . $code . ': ' . substr($resp, 0, 200)];
}

// ---- Vista detalle por proveedor concreto ----
$detailProv = isset($_GET['proveedor_id']) ? (int)$_GET['proveedor_id'] : 0;
if ($detailProv > 0) {
    $stmt = $pdo->prepare("SELECT pv.*, pr.slug, pr.client_name, pr.version AS prop_version, pr.html_content
                           FROM propuesta_proveedores pv
                           JOIN propuestas pr ON pr.id = pv.propuesta_id
                           WHERE pv.id = ?");
    $stmt->execute([$detailProv]);
    $pv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pv) {
        http_response_code(404);
        echo 'Proveedor no encontrado';
        exit;
    }

    // Presupuestos del proveedor
    $bq = $pdo->prepare("SELECT * FROM proveedor_presupuestos WHERE proveedor_id = ? ORDER BY version_num DESC");
    $bq->execute([$detailProv]);
    $budgets = $bq->fetchAll(PDO::FETCH_ASSOC);

    // Mensajes del proveedor
    $mq = $pdo->prepare("SELECT * FROM proveedor_mensajes WHERE proveedor_id = ? AND is_draft = 0 ORDER BY COALESCE(parent_id, id) ASC, created_at ASC");
    $mq->execute([$detailProv]);
    $provMessages = $mq->fetchAll(PDO::FETCH_ASSOC);
    $mRoots = array_filter($provMessages, fn($m) => !$m['parent_id']);
    $mReplies = [];
    foreach ($provMessages as $m) { if ($m['parent_id']) $mReplies[$m['parent_id']][] = $m; }

    $host = $_SERVER['HTTP_HOST'] ?? 'doc.trespuntos-lab.com';
    $scheme = (($_SERVER['HTTPS'] ?? '') === 'on' || strpos($host, 'localhost') === false) ? 'https' : 'http';
    $providerUrl = $scheme . '://' . $host . '/s/' . $pv['token'];
    ?>
    <!doctype html>
    <html lang="es"><head><meta charset="utf-8"><title>Proveedor · <?=e($pv['nombre'])?></title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
    :root{--mint:#5dffbf;--mint-rgb:93,255,191;--bg-base:#0e0e0e;--bg-surface:#141414;--bg-subtle:#191919;--bg-muted:#1f1f1f;--text-primary:#f5f5f5;--text-secondary:#b3b3b3;--text-muted:#8a8a8a;--border-base:#1f1f1f;--border-strong:#2a2a2a;--purple:#c084fc;--purple-rgb:192,132,252;}
    *{box-sizing:border-box;}body{margin:0;background:var(--bg-base);color:var(--text-primary);font:14px/1.55 system-ui,sans-serif;}
    header{padding:1.1rem 2rem;border-bottom:1px solid var(--border-base);background:var(--bg-surface);position:sticky;top:0;z-index:10;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;}
    header h1{margin:0;font-size:1.05rem;}header a{color:var(--mint);text-decoration:none;}
    .pv-detail-grid{display:grid;grid-template-columns:1.3fr 1fr;gap:2rem;}
    @media (max-width:1000px){.pv-detail-grid{grid-template-columns:1fr;}}
    .card{background:var(--bg-surface);border:1px solid var(--border-base);border-radius:12px;padding:1.25rem 1.5rem;margin-bottom:1.5rem;}
    h2{font-size:.95rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.04em;margin:0 0 1rem;border-bottom:1px solid var(--border-base);padding-bottom:.5rem;}
    h3{font-size:.95rem;margin:1rem 0 .5rem;}
    .identity{display:flex;gap:.85rem;align-items:center;margin-bottom:1rem;}
    .avatar{width:48px;height:48px;border-radius:999px;background:rgba(var(--purple-rgb),.15);color:var(--purple);border:1px solid rgba(var(--purple-rgb),.35);display:grid;place-items:center;font-weight:700;font-size:1.1rem;}
    .ident-meta small{color:var(--text-muted);}
    .access-block{background:var(--bg-muted);padding:.85rem;border-radius:8px;font-family:monospace;font-size:.8rem;margin:1rem 0;word-break:break-all;}
    .access-block .label{font-size:.68rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;font-weight:700;font-family:system-ui;margin-bottom:.25rem;}
    .access-block .pin{color:var(--mint);font-size:1.1rem;letter-spacing:.2em;font-weight:700;}
    .btn{background:var(--mint);color:#000;border:none;padding:.5rem .95rem;border-radius:6px;font-weight:700;cursor:pointer;font-family:inherit;font-size:.82rem;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem;}
    .btn:hover{background:#49e6a8;}
    .btn-outline{background:transparent;color:var(--text-secondary);border:1px solid var(--border-base);}
    .btn-outline:hover{color:var(--text-primary);border-color:var(--border-strong);}
    .btn-purple{background:rgba(var(--purple-rgb),.2);color:var(--purple);border:1px solid rgba(var(--purple-rgb),.4);}
    .btn-purple:hover{background:rgba(var(--purple-rgb),.3);}

    /* Presupuestos */
    .budget{padding:.7rem 0;border-bottom:1px dashed var(--border-base);display:grid;grid-template-columns:50px 1fr auto;gap:1rem;align-items:center;font-size:.85rem;}
    .budget:last-child{border-bottom:0;}
    .budget-v{background:var(--mint);color:#000;padding:.1rem .5rem;border-radius:999px;font-size:.7rem;font-weight:700;text-align:center;}
    .budget-meta{color:var(--text-secondary);font-size:.75rem;margin-top:.2rem;}
    .budget-notes{color:var(--text-muted);font-size:.78rem;margin-top:.3rem;font-style:italic;}

    /* Mensajes */
    .thread{border:1px solid var(--border-base);border-radius:10px;margin-bottom:1rem;overflow:hidden;}
    .thread-head{background:var(--bg-subtle);padding:.65rem .95rem;font-size:.75rem;color:var(--text-muted);display:flex;justify-content:space-between;}
    .thread-body{padding:.75rem .95rem;}
    .msg{padding:.55rem 0;}
    .msg+.msg{border-top:1px dashed var(--border-base);margin-top:.55rem;}
    .msg.reply{padding-left:.8rem;border-left:2px solid var(--mint);margin-top:.5rem;background:rgba(var(--mint-rgb),.04);border-radius:4px;padding-right:.6rem;padding-top:.5rem;padding-bottom:.5rem;}
    .msg-author{font-weight:700;font-size:.8rem;}.msg-author.staff{color:var(--mint);}
    .msg-text{color:var(--text-primary);font-size:.86rem;line-height:1.55;white-space:pre-wrap;margin-top:.2rem;}
    .reply-form{padding:.55rem .95rem 1rem;background:var(--bg-base);border-top:1px solid var(--border-base);}
    .reply-form textarea{width:100%;box-sizing:border-box;background:var(--bg-subtle);color:var(--text-primary);border:1px solid var(--border-base);padding:.55rem;border-radius:6px;font-family:inherit;font-size:.85rem;min-height:55px;}
    .reply-form textarea:focus{outline:none;border-color:var(--mint);}
    .reply-form .row{display:flex;justify-content:flex-end;margin-top:.35rem;}

    /* Embedded doc view */
    /* Sync card — indica que doc está sincronizado con cliente */
    .sync-card{display:flex;flex-direction:column;gap:.85rem;}
    .sync-head{display:flex;align-items:flex-start;gap:.75rem;}
    .sync-dot{width:8px;height:8px;border-radius:50%;background:var(--mint);box-shadow:0 0 8px rgba(var(--mint-rgb),.5);flex-shrink:0;margin-top:.4rem;animation:syncPulse 2.2s ease-in-out infinite;}
    @keyframes syncPulse{0%,100%{opacity:1;box-shadow:0 0 8px rgba(var(--mint-rgb),.5);}50%{opacity:.55;box-shadow:0 0 4px rgba(var(--mint-rgb),.3);}}
    .sync-info{flex:1;min-width:0;}
    .sync-title{font-weight:600;font-size:.88rem;color:var(--text-primary);letter-spacing:-.005em;}
    .sync-sub{font-size:.75rem;color:var(--text-muted);margin-top:.15rem;line-height:1.45;}
    .sync-actions{display:flex;gap:.5rem;flex-wrap:wrap;}

    .kpi-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:.6rem;margin-bottom:1rem;}
    .kpi{background:var(--bg-muted);padding:.6rem .8rem;border-radius:6px;}
    .kpi-label{font-size:.65rem;text-transform:uppercase;letter-spacing:.04em;color:var(--text-muted);font-weight:600;}
    .kpi-value{font-size:1.25rem;font-weight:700;margin-top:.15rem;}
    </style></head><body>

    <?php
    // Sidebar para la vista detalle del proveedor
    $adminSidebarActive = 'proveedores';
    $adminSidebarPropuestaId = (int)$pv['propuesta_id'];
    $adminSidebarPropuestaSlug = $pv['slug'];
    $adminSidebarPropuestas = $pdo->query("SELECT id, slug, client_name FROM propuestas WHERE status = 1 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="admin-layout">
    <?php include __DIR__ . '/master/admin-sidebar.php'; ?>

    <main class="admin-main">
        <?php
        // H3: breadcrumb detalle proveedor
        $adminBreadcrumbItems = [
            ['label' => 'Dashboard', 'href' => 'admin.php'],
            ['label' => e($pv['client_name']), 'href' => 'admin_providers.php?propuesta_id=' . (int)$pv['propuesta_id']],
            ['label' => 'Proveedores', 'href' => 'admin_providers.php?propuesta_id=' . (int)$pv['propuesta_id']],
            ['label' => e($pv['nombre']) . ($pv['empresa'] ? ' · ' . e($pv['empresa']) : ''), 'href' => null],
        ];
        $adminBreadcrumbPropNav = null;
        include __DIR__ . '/master/admin-breadcrumb.php';
        ?>
        <div class="admin-main-header">
            <h1 class="admin-main-title">
                <i data-lucide="hard-hat"></i>
                <?= e($pv['nombre']) ?>
                <?php if ($pv['empresa']): ?><small>· <?= e($pv['empresa']) ?></small><?php endif; ?>
            </h1>
            <div class="admin-main-actions">
                <a href="admin_providers.php?propuesta_id=<?=(int)$pv['propuesta_id']?>" style="color:var(--mint);text-decoration:none;font-size:.82rem;display:inline-flex;align-items:center;gap:.3rem;">
                    <i data-lucide="arrow-left" style="width:14px;height:14px;"></i> Todos los proveedores
                </a>
            </div>
        </div>

        <div class="pv-detail-grid">
        <!-- Columna izquierda -->
        <div>
            <section class="card">
                <h2>Identidad · acceso</h2>
                <div class="identity">
                    <div class="avatar"><?=e(mb_strtoupper(mb_substr($pv['nombre'],0,1)))?></div>
                    <div class="ident-meta">
                        <div style="font-weight:600;font-size:1rem;"><?=e($pv['nombre'])?></div>
                        <?php if ($pv['empresa']): ?><div style="color:var(--text-muted);font-size:.8rem;"><?=e($pv['empresa'])?></div><?php endif; ?>
                        <div style="color:var(--text-secondary);font-size:.78rem;"><?=e($pv['email'])?></div>
                    </div>
                </div>

                <div class="kpi-row">
                    <div class="kpi"><div class="kpi-label">Accesos</div><div class="kpi-value"><?=$pv['accesos']?></div></div>
                    <div class="kpi"><div class="kpi-label">Presupuestos</div><div class="kpi-value"><?=count($budgets)?></div></div>
                    <div class="kpi"><div class="kpi-label">Mensajes</div><div class="kpi-value"><?=count($mRoots)?></div></div>
                </div>

                <div class="access-block">
                    <div class="label">URL</div>
                    <?=e($providerUrl)?>
                    <div class="label" style="margin-top:.5rem;">PIN</div>
                    <div class="pin"><?=e($pv['pin'])?></div>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <a class="btn" href="/p/<?=urlencode($pv['slug'])?>?__provider=<?=urlencode($pv['token'])?>&__admin_view=1" target="_blank" style="display:inline-flex;align-items:center;gap:.35rem;">
                        <i data-lucide="file-text" style="width:14px;height:14px;"></i> Ver doc con comentarios inline
                    </a>
                    <button class="btn btn-outline" onclick="copyAccess('<?=e($providerUrl)?>','<?=e($pv['pin'])?>')"><i data-lucide="clipboard" style="width:14px;height:14px;vertical-align:-2px;"></i> Copiar URL + PIN</button>
                    <a class="btn btn-purple" href="<?=e($providerUrl)?>" target="_blank"><i data-lucide="external-link" style="width:14px;height:14px;vertical-align:-2px;"></i> Abrir vista real</a>
                </div>
                <div style="font-size:.72rem;color:var(--text-muted);margin-top:.75rem;">
                    Invitado <?=fecha($pv['invited_at'])?>
                    <?=$pv['last_accessed_at'] ? ' · Último acceso ' . fecha($pv['last_accessed_at']) : ' · Aún sin entrar'?>
                </div>
            </section>

            <section class="card">
                <h2>Presupuestos enviados (<?=count($budgets)?>)</h2>
                <?php if (!$budgets): ?>
                    <p style="color:var(--text-muted);font-size:.85rem;">Aún sin subir presupuesto.</p>
                <?php else: foreach ($budgets as $b): ?>
                    <div class="budget">
                        <div class="budget-v">v<?=$b['version_num']?></div>
                        <div>
                            <div style="font-weight:500;"><?=e($b['archivo_nombre'])?></div>
                            <div class="budget-meta">
                                <?=fecha($b['uploaded_at'])?>
                                <?php if ($b['importe_total']): ?> · <i data-lucide="euro" style="width:12px;height:12px;vertical-align:-1px;"></i> <?=number_format((float)$b['importe_total'],2,',','.')?>€<?php endif; ?>
                                <?php if ($b['plazo_dias']): ?> · <i data-lucide="clock" style="width:12px;height:12px;vertical-align:-1px;"></i> <?=$b['plazo_dias']?>d<?php endif; ?>
                            </div>
                            <?php if ($b['notas']): ?><div class="budget-notes"><?=e($b['notas'])?></div><?php endif; ?>
                        </div>
                        <div>
                            <a href="admin_providers.php?download=<?=(int)$b['id']?>" target="_blank" class="btn btn-outline" style="font-size:.72rem;padding:.25rem .55rem;display:inline-flex;align-items:center;gap:.3rem;"><i data-lucide="file-text" style="width:12px;height:12px;"></i> PDF</a>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </section>
        </div>

        <!-- Columna derecha -->
        <div>
            <section class="card">
                <h2>Mensajes (<?=count($mRoots)?>)</h2>
                <?php if (!$mRoots): ?>
                    <p style="color:var(--text-muted);font-size:.85rem;">Sin mensajes del proveedor.</p>
                <?php else: foreach ($mRoots as $root): $replies = $mReplies[$root['id']] ?? []; ?>
                <article class="thread">
                    <header class="thread-head">
                        <div><?=$root['section_title'] ? '<strong>' . e($root['section_title']) . '</strong>' : 'General'?></div>
                        <div><?=fecha($root['created_at'])?></div>
                    </header>
                    <div class="thread-body">
                        <div class="msg">
                            <div class="msg-author"><?=e($root['autor_nombre'] ?: $pv['nombre'])?></div>
                            <div class="msg-text"><?=e($root['texto'])?></div>
                        </div>
                        <?php foreach ($replies as $r): ?>
                        <div class="msg reply">
                            <div class="msg-author <?=$r['autor_tipo']==='staff'?'staff':''?>">
                                <?=$r['autor_tipo']==='staff' ? 'Tres Puntos' : e($r['autor_nombre'])?>
                                <span style="color:var(--text-muted);font-weight:400;font-size:.7rem;margin-left:.4rem;"><?=fecha($r['created_at'])?></span>
                            </div>
                            <div class="msg-text"><?=e($r['texto'])?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <form class="reply-form" onsubmit="return replyProviderMsg(event,<?=$root['id']?>)">
                        <textarea name="texto" placeholder="Responder al proveedor…" required></textarea>
                        <div class="row"><button type="submit" class="btn">Responder</button></div>
                    </form>
                </article>
                <?php endforeach; endif; ?>
            </section>

            <section class="card sync-card">
                <div class="sync-head">
                    <div class="sync-dot"></div>
                    <div class="sync-info">
                        <div class="sync-title">Sincronizado con la <?=e($pv['prop_version'])?></div>
                        <div class="sync-sub">El proveedor ve la misma versión que el cliente · actualiza al instante</div>
                    </div>
                </div>
                <div class="sync-actions">
                    <a href="/p/<?=urlencode($pv['slug'])?>?__provider=<?=urlencode($pv['token'])?>&__admin_view=1" target="_blank" rel="noopener" class="btn btn-outline" style="font-size:.75rem;padding:.4rem .75rem;display:inline-flex;align-items:center;gap:.35rem;">
                        <i data-lucide="file-text" style="width:13px;height:13px;"></i>
                        Ver doc como este proveedor
                        <i data-lucide="arrow-up-right" style="width:12px;height:12px;opacity:.55;"></i>
                    </a>
                    <a href="/p/<?=urlencode($pv['slug'])?>?__admin_view=1" target="_blank" rel="noopener" class="btn btn-outline" style="font-size:.75rem;padding:.4rem .75rem;display:inline-flex;align-items:center;gap:.35rem;">
                        <i data-lucide="eye" style="width:13px;height:13px;"></i>
                        Ver doc del cliente
                        <i data-lucide="arrow-up-right" style="width:12px;height:12px;opacity:.55;"></i>
                    </a>
                </div>
            </section>
        </div>
        </div><!-- /.pv-detail-grid -->
    </main>
    </div><!-- /.admin-layout -->

    <script>
    function copyAccess(url, pin) {
        navigator.clipboard.writeText(url + '\nPIN: ' + pin).then(() => alert('Acceso copiado'));
    }
    async function replyProviderMsg(e, parentId) {
        e.preventDefault();
        const ta = e.target.querySelector('textarea');
        const texto = ta.value.trim();
        if (!texto) return false;
        const r = await fetch('admin_providers.php', {method:'POST', body: new URLSearchParams({action:'reply_to_provider_msg', parent_id: parentId, texto})}).then(r=>r.json()).catch(()=>({}));
        if (r.success) location.reload(); else alert(r.error || 'Error');
        return false;
    }
    if (window.lucide) lucide.createIcons();
    </script>
    </body></html>
    <?php
    exit;
}

// ---- Query data (listado general) ----
$filterProp = isset($_GET['propuesta_id']) ? (int)$_GET['propuesta_id'] : 0;
$propuestas = $pdo->query("SELECT id, slug, client_name FROM propuestas WHERE status = 1 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

$currentProp = null;
if ($filterProp) {
    $cp = $pdo->prepare("SELECT * FROM propuestas WHERE id = ?");
    $cp->execute([$filterProp]);
    $currentProp = $cp->fetch(PDO::FETCH_ASSOC);
}

$proveedores = [];
if ($filterProp && $currentProp) {
    $pq = $pdo->prepare("SELECT p.*,
        (SELECT COUNT(*) FROM proveedor_presupuestos WHERE proveedor_id = p.id) AS n_presupuestos,
        (SELECT COUNT(*) FROM proveedor_mensajes WHERE proveedor_id = p.id AND is_draft = 0) AS n_mensajes,
        (SELECT id FROM proveedor_presupuestos WHERE proveedor_id = p.id ORDER BY uploaded_at DESC LIMIT 1) AS last_budget_id,
        (SELECT importe_total FROM proveedor_presupuestos WHERE proveedor_id = p.id ORDER BY uploaded_at DESC LIMIT 1) AS last_importe,
        (SELECT plazo_dias FROM proveedor_presupuestos WHERE proveedor_id = p.id ORDER BY uploaded_at DESC LIMIT 1) AS last_plazo,
        (SELECT version_num FROM proveedor_presupuestos WHERE proveedor_id = p.id ORDER BY uploaded_at DESC LIMIT 1) AS last_version
        FROM propuesta_proveedores p
        WHERE p.propuesta_id = ?
        ORDER BY p.invited_at DESC");
    $pq->execute([$filterProp]);
    $proveedores = $pq->fetchAll(PDO::FETCH_ASSOC);
}

// Directorio global de proveedores (cuando no hay propuesta_id)
$globalProveedores = [];
if (!$filterProp) {
    try {
        $gq = $pdo->query("SELECT p.id, p.nombre, p.empresa, p.email, p.activo, p.last_accessed_at, p.accesos, p.invited_at,
            p.propuesta_id, pr.client_name AS propuesta_cliente, pr.slug AS propuesta_slug,
            (SELECT COUNT(*) FROM proveedor_presupuestos WHERE proveedor_id = p.id) AS n_presupuestos,
            (SELECT COUNT(*) FROM proveedor_mensajes WHERE proveedor_id = p.id AND is_draft = 0) AS n_mensajes
            FROM propuesta_proveedores p
            LEFT JOIN propuestas pr ON pr.id = p.propuesta_id
            ORDER BY p.activo DESC, p.nombre ASC, p.invited_at DESC");
        $globalProveedores = $gq->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $globalProveedores = []; }
}

// Mensajes de todos los proveedores de esta propuesta (si vista con filtro)
$mensajes = [];
if ($filterProp) {
    $mq = $pdo->prepare("SELECT m.*, pv.nombre AS proveedor_nombre, pv.empresa AS proveedor_empresa
        FROM proveedor_mensajes m
        JOIN propuesta_proveedores pv ON pv.id = m.proveedor_id
        WHERE pv.propuesta_id = ? AND m.is_draft = 0
        ORDER BY COALESCE(m.parent_id, m.id) ASC, m.created_at ASC");
    $mq->execute([$filterProp]);
    $mensajes = $mq->fetchAll(PDO::FETCH_ASSOC);
}

?>
<!doctype html>
<html lang="es"><head>
<meta charset="utf-8"><title>Admin · Proveedores</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
:root{--mint:#5dffbf;--mint-rgb:93,255,191;--bg-base:#0e0e0e;--bg-surface:#141414;--bg-subtle:#191919;--bg-muted:#1f1f1f;--text-primary:#f5f5f5;--text-secondary:#b3b3b3;--text-muted:#8a8a8a;--border-base:#1f1f1f;--border-strong:#2a2a2a;}
*{box-sizing:border-box;} body{margin:0;background:var(--bg-base);color:var(--text-primary);font:14px/1.55 system-ui,sans-serif;}
header{padding:1.25rem 2rem;border-bottom:1px solid var(--border-base);background:var(--bg-surface);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;position:sticky;top:0;z-index:10;}
h1{margin:0;font-size:1.15rem;} h2{font-size:1rem;color:var(--text-secondary);letter-spacing:.04em;text-transform:uppercase;margin:2rem 0 .75rem;border-bottom:1px solid var(--border-base);padding-bottom:.5rem;}
h3{font-size:.9rem;margin:1.5rem 0 .5rem;}
/* main global removido — la página usa .admin-main (sidebar layout) */
header a{color:var(--mint);text-decoration:none;}
select{background:var(--bg-subtle);border:1px solid var(--border-base);color:var(--text-primary);padding:.4rem .6rem;border-radius:6px;}
.cliente{color:var(--mint);font-weight:600;}
.empty{color:var(--text-muted);padding:2rem;text-align:center;background:var(--bg-surface);border-radius:8px;}
.btn{background:var(--mint);color:#000;border:none;padding:.55rem 1rem;border-radius:6px;font-weight:700;cursor:pointer;font-family:inherit;font-size:.82rem;}
.btn:hover{background:#49e6a8;}
.btn-outline{background:transparent;color:var(--text-secondary);border:1px solid var(--border-base);}
.btn-outline:hover{color:var(--text-primary);border-color:var(--border-strong);}
.btn-danger{color:#ff6b6b;border:1px solid rgba(255,107,107,.3);background:transparent;}
.btn-danger:hover{border-color:#ff6b6b;background:rgba(255,107,107,.08);}

/* Invitar form */
.invite-form{background:var(--bg-surface);border:1px solid var(--border-base);border-radius:10px;padding:1.25rem 1.5rem;margin-bottom:2rem;}
.invite-form .row{display:grid;grid-template-columns:1fr 1fr 1.2fr;gap:.75rem;margin-bottom:.75rem;}
.invite-form input[type=text],.invite-form input[type=email]{width:100%;box-sizing:border-box;background:var(--bg-subtle);border:1px solid var(--border-base);color:var(--text-primary);padding:.55rem .75rem;border-radius:6px;font-family:inherit;}
.invite-form input:focus{outline:none;border-color:var(--mint);}
.invite-form label.chk{display:inline-flex;align-items:center;gap:.4rem;color:var(--text-secondary);font-size:.82rem;margin-right:1.5rem;}
.invite-form .controls{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;margin-top:.5rem;}

/* Table proveedores */
table{width:100%;border-collapse:collapse;background:var(--bg-surface);border-radius:8px;overflow:hidden;}
th,td{padding:.75rem 1rem;text-align:left;border-bottom:1px solid var(--border-base);vertical-align:top;font-size:.85rem;}
th{background:var(--bg-subtle);color:var(--text-secondary);font-weight:600;font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;}
tr:last-child td{border-bottom:0;} tr.inactive{opacity:.4;}
.pill{display:inline-block;padding:.1rem .5rem;border-radius:999px;font-size:.68rem;font-weight:600;}
.pill.success{background:rgba(var(--mint-rgb),.15);color:var(--mint);}
.pill.muted{background:var(--bg-muted);color:var(--text-muted);}
.pill.err{background:rgba(255,107,107,.14);color:#ff6b6b;}

/* Card cuando acabas de crear uno */
.access-card{background:linear-gradient(135deg,rgba(var(--mint-rgb),.14),rgba(var(--mint-rgb),.03));border:1px solid rgba(var(--mint-rgb),.4);border-radius:12px;padding:1.5rem;margin-bottom:2rem;display:none;}
.access-card.visible{display:block;}
.access-card .label{font-size:.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.25rem;font-weight:700;}
.access-card .val{font-family:monospace;word-break:break-all;color:var(--text-primary);padding:.5rem .75rem;background:var(--bg-muted);border-radius:6px;margin-bottom:.75rem;font-size:.85rem;}
.access-card .pin{font-size:1.5rem;letter-spacing:.2em;font-weight:700;}

/* Mensajes */
.thread{background:var(--bg-surface);border:1px solid var(--border-base);border-radius:10px;margin-bottom:1rem;overflow:hidden;}
.thread-head{padding:.75rem 1rem;background:var(--bg-subtle);font-size:.78rem;color:var(--text-muted);display:flex;justify-content:space-between;}
.thread-head .prov{color:var(--mint);font-weight:700;}
.thread-body{padding:.75rem 1rem;}
.msg{padding:.55rem 0;border-bottom:1px dashed var(--border-base);}
.msg:last-of-type{border-bottom:0;}
.msg.reply{padding-left:1rem;border-left:2px solid var(--mint);margin-left:.3rem;margin-top:.3rem;}
.msg-author{font-weight:700;font-size:.8rem;}
.msg-author.staff{color:var(--mint);}
.msg-text{color:var(--text-primary);font-size:.88rem;line-height:1.55;white-space:pre-wrap;margin-top:.2rem;}
.reply-form{padding:.5rem 1rem 1rem;background:var(--bg-base);border-top:1px solid var(--border-base);}
.reply-form textarea{width:100%;box-sizing:border-box;background:var(--bg-subtle);color:var(--text-primary);border:1px solid var(--border-base);padding:.55rem;border-radius:6px;font-family:inherit;font-size:.88rem;min-height:60px;}
.reply-form textarea:focus{outline:none;border-color:var(--mint);}
.reply-form .row{display:flex;gap:.5rem;margin-top:.4rem;justify-content:flex-end;}
</style>
<script src="https://unpkg.com/lucide@latest"></script>
</head><body>

<?php
$adminSidebarActive = 'proveedores';
$adminSidebarPropuestaId = $filterProp;
$adminSidebarPropuestaSlug = $filterProp > 0 && $currentProp ? ($currentProp['slug'] ?? null) : null;
$adminSidebarPropuestas = $propuestas;
?>
<div class="admin-layout">
<?php include __DIR__ . '/master/admin-sidebar.php'; ?>

<main class="admin-main">
    <?php
    // H3 + H5: breadcrumb + nav prev/next
    if ($filterProp > 0 && !empty($currentProp)) {
        $adminBreadcrumbItems = [
            ['label' => 'Dashboard', 'href' => 'admin.php'],
            ['label' => e($currentProp['client_name']), 'href' => null],
            ['label' => 'Proveedores', 'href' => null],
        ];
        $adminBreadcrumbPropNav = ['current_id' => $filterProp, 'view' => 'proveedores'];
    } else {
        $adminBreadcrumbItems = [
            ['label' => 'Dashboard', 'href' => 'admin.php'],
            ['label' => 'Proveedores (todos)', 'href' => null],
        ];
        $adminBreadcrumbPropNav = null;
    }
    include __DIR__ . '/master/admin-breadcrumb.php';
    ?>
    <div class="admin-main-header">
        <h1 class="admin-main-title">
            <i data-lucide="hard-hat"></i>
            Proveedores
            <?php if ($filterProp > 0 && !empty($currentProp)): ?>
                <small>· <?= e($currentProp['client_name']) ?></small>
            <?php endif; ?>
        </h1>
    </div>

<?php if (!$filterProp): ?>
    <!-- DIRECTORIO GLOBAL DE PROVEEDORES -->
    <style>
        /* Safety net: ningún SVG Lucide del directorio crece más de lo debido */
        .pv-dir-grid svg.lucide,
        .pv-dir-grid i[data-lucide] { max-width: 14px; max-height: 14px; }
        .pv-dir-toolbar svg.lucide,
        .pv-dir-toolbar i[data-lucide] { max-width: 14px; max-height: 14px; }

        .pv-dir-toolbar { display: flex; gap: .75rem; align-items: center; margin: 0 0 1rem; flex-wrap: wrap; }
        .pv-dir-search { flex: 1; min-width: 240px; position: relative; }
        .pv-dir-search input { width: 100%; box-sizing: border-box; background: var(--bg-subtle); border: 1px solid var(--border-base); color: var(--text-primary); padding: .55rem .75rem .55rem 2.1rem; border-radius: 8px; font-family: inherit; font-size: .88rem; outline: none; transition: border-color .12s; }
        .pv-dir-search input:focus { border-color: var(--mint); }
        .pv-dir-search i[data-lucide],
        .pv-dir-search svg.lucide { position: absolute !important; left: .7rem; top: 50%; transform: translateY(-50%); width: 14px !important; height: 14px !important; color: var(--text-muted); pointer-events: none; }
        .pv-dir-count { color: var(--text-muted); font-size: .8rem; font-variant-numeric: tabular-nums; }
        .pv-dir-empty { color: var(--text-muted); padding: 3rem 2rem; text-align: center; background: var(--bg-surface); border-radius: 10px; border: 1px dashed var(--border-base); }
        .pv-dir-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: .85rem; }
        .pv-dir-card { background: var(--bg-surface); border: 1px solid var(--border-base); border-radius: 10px; padding: 1rem 1.1rem; text-decoration: none; color: inherit; transition: border-color .15s, transform .15s, background-color .15s; display: block; }
        .pv-dir-card:hover { border-color: var(--border-strong); transform: translateY(-1px); background: rgba(255,255,255,0.01); }
        .pv-dir-card.inactive { opacity: .55; }
        .pv-dir-card__head { display: flex; align-items: center; gap: .65rem; margin-bottom: .55rem; }
        .pv-dir-card__avatar { width: 34px; height: 34px; border-radius: 50%; background: rgba(192,132,252,.12); color: #c084fc; display: grid; place-items: center; font-weight: 700; font-size: .95rem; border: 1px solid rgba(192,132,252,.22); flex-shrink: 0; }
        .pv-dir-card__name { font-weight: 600; color: var(--text-primary); font-size: .92rem; line-height: 1.2; }
        .pv-dir-card__empresa { font-size: .75rem; color: var(--text-muted); margin-top: 2px; }
        .pv-dir-card__status { margin-left: auto; font-size: .65rem; text-transform: uppercase; letter-spacing: .06em; font-weight: 600; }
        .pv-dir-card__status.active { color: var(--mint); }
        .pv-dir-card__status.inactive { color: var(--text-muted); }
        .pv-dir-card__meta { display: flex; flex-direction: column; gap: .35rem; font-size: .78rem; color: var(--text-secondary); }
        .pv-dir-card__meta-row { display: flex; align-items: center; gap: .5rem; }
        .pv-dir-card__meta-row i[data-lucide],
        .pv-dir-card__meta-row svg.lucide { width: 12px !important; height: 12px !important; color: var(--text-muted); stroke-width: 1.75; flex-shrink: 0; }
        .pv-dir-card__stats { display: flex; gap: .85rem; margin-top: .65rem; padding-top: .65rem; border-top: 1px dashed var(--border-base); font-size: .75rem; }
        .pv-dir-card__stat { display: flex; align-items: center; gap: .3rem; color: var(--text-muted); font-variant-numeric: tabular-nums; }
        .pv-dir-card__stat strong { color: var(--text-secondary); font-weight: 600; }
        .pv-dir-card__stat i[data-lucide],
        .pv-dir-card__stat svg.lucide { width: 12px !important; height: 12px !important; flex-shrink: 0; }
        .pv-dir-hint { margin-top: 1.5rem; padding: .85rem 1rem; background: rgba(var(--mint-rgb), .06); border: 1px solid rgba(var(--mint-rgb), .18); border-radius: 8px; color: var(--text-secondary); font-size: .82rem; line-height: 1.5; }
        .pv-dir-hint strong { color: var(--mint); }
    </style>

    <div class="pv-dir-toolbar">
        <div class="pv-dir-search">
            <i data-lucide="search"></i>
            <input type="text" id="pv-dir-search-input" placeholder="Buscar proveedor por nombre, empresa o email…" autocomplete="off">
        </div>
        <span class="pv-dir-count"><?= count($globalProveedores) ?> proveedor<?= count($globalProveedores) === 1 ? '' : 'es' ?></span>
    </div>

    <?php if (empty($globalProveedores)): ?>
        <div class="pv-dir-empty">
            <i data-lucide="hard-hat" style="width: 32px; height: 32px; color: var(--text-muted); opacity: .5;"></i>
            <p style="margin: .75rem 0 .25rem; font-size: .95rem; color: var(--text-secondary);">Aún no has invitado proveedores</p>
            <p style="margin: 0; font-size: .82rem; color: var(--text-muted);">Desde cualquier propuesta → Proveedores → Invitar proveedor.</p>
        </div>
    <?php else: ?>
        <div class="pv-dir-grid" id="pv-dir-grid">
            <?php foreach ($globalProveedores as $gp):
                $gpInitial = mb_strtoupper(mb_substr($gp['nombre'] ?? '?', 0, 1));
                $gpActive = (int)$gp['activo'] === 1;
                $gpSearch = strtolower(trim(($gp['nombre'] ?? '') . ' ' . ($gp['empresa'] ?? '') . ' ' . ($gp['email'] ?? '') . ' ' . ($gp['propuesta_cliente'] ?? '')));
            ?>
                <a href="admin_providers.php?proveedor_id=<?= (int)$gp['id'] ?>"
                   class="pv-dir-card<?= $gpActive ? '' : ' inactive' ?>"
                   data-search="<?= e($gpSearch) ?>">
                    <div class="pv-dir-card__head">
                        <div class="pv-dir-card__avatar"><?= e($gpInitial) ?></div>
                        <div style="min-width:0;">
                            <div class="pv-dir-card__name"><?= e($gp['nombre'] ?? '—') ?></div>
                            <?php if (!empty($gp['empresa'])): ?>
                                <div class="pv-dir-card__empresa"><?= e($gp['empresa']) ?></div>
                            <?php endif; ?>
                        </div>
                        <span class="pv-dir-card__status <?= $gpActive ? 'active' : 'inactive' ?>">
                            <?= $gpActive ? 'Activo' : 'Revocado' ?>
                        </span>
                    </div>

                    <div class="pv-dir-card__meta">
                        <?php if (!empty($gp['email'])): ?>
                            <div class="pv-dir-card__meta-row" title="Email">
                                <i data-lucide="mail"></i>
                                <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= e($gp['email']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($gp['propuesta_cliente'])): ?>
                            <div class="pv-dir-card__meta-row" title="Propuesta para la que fue invitado">
                                <i data-lucide="briefcase"></i>
                                <span><?= e($gp['propuesta_cliente']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($gp['last_accessed_at'])): ?>
                            <div class="pv-dir-card__meta-row" title="Último acceso">
                                <i data-lucide="clock"></i>
                                <span>Último acceso: <?= date('d/m/y', strtotime($gp['last_accessed_at'])) ?></span>
                            </div>
                        <?php else: ?>
                            <div class="pv-dir-card__meta-row" title="Nunca ha entrado">
                                <i data-lucide="clock"></i>
                                <span style="color: var(--text-muted);">Nunca ha entrado</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="pv-dir-card__stats">
                        <span class="pv-dir-card__stat" title="Presupuestos enviados">
                            <i data-lucide="file-text"></i> <strong><?= (int)$gp['n_presupuestos'] ?></strong> presupuesto<?= (int)$gp['n_presupuestos'] === 1 ? '' : 's' ?>
                        </span>
                        <span class="pv-dir-card__stat" title="Mensajes intercambiados">
                            <i data-lucide="message-circle"></i> <strong><?= (int)$gp['n_mensajes'] ?></strong> mensaje<?= (int)$gp['n_mensajes'] === 1 ? '' : 's' ?>
                        </span>
                        <span class="pv-dir-card__stat" title="Accesos al portal">
                            <i data-lucide="log-in"></i> <strong><?= (int)$gp['accesos'] ?></strong>
                        </span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="pv-dir-hint">
            <strong>Próximo paso:</strong> al entrar en el detalle de un proveedor se guardan sus presupuestos y mensajes. En el siguiente sprint añadiremos <strong>perfiles completos</strong> con contratos, documentos y datos fiscales.
        </div>
    <?php endif; ?>

    <script>
    (function () {
        const input = document.getElementById('pv-dir-search-input');
        const cards = document.querySelectorAll('#pv-dir-grid .pv-dir-card');
        if (!input || !cards.length) return;
        function norm(s) { return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
        input.addEventListener('input', e => {
            const q = norm(e.target.value.trim());
            let visible = 0;
            cards.forEach(c => {
                const haystack = norm(c.getAttribute('data-search') || '');
                const show = !q || haystack.includes(q);
                c.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            const countEl = document.querySelector('.pv-dir-count');
            if (countEl) countEl.textContent = q ? (visible + ' coincidencia' + (visible === 1 ? '' : 's')) : (cards.length + ' proveedor' + (cards.length === 1 ? '' : 'es'));
        });
    })();
    </script>
<?php else: ?>

<h2>Invitar proveedor a <?=e($currentProp['client_name'])?></h2>
<div class="invite-form">
    <form id="invite-form" onsubmit="return inviteProvider(event)">
        <input type="hidden" name="propuesta_id" value="<?=$filterProp?>">
        <div class="row">
            <input type="text" name="nombre" placeholder="Nombre del contacto" required>
            <input type="text" name="empresa" placeholder="Empresa (opcional)">
            <input type="email" name="email" placeholder="email@proveedor.com" required>
        </div>
        <div class="controls">
            <div>
                <label class="chk"><input type="checkbox" name="ver_comentarios" checked> Puede ver comentarios del cliente</label>
                <label class="chk"><input type="checkbox" name="send_email" checked> Enviar email de invitación</label>
            </div>
            <button type="submit" class="btn">Generar acceso</button>
        </div>
    </form>
</div>

<div class="access-card" id="access-card">
    <h3 style="margin-top:0;">✓ Acceso creado</h3>
    <div class="label">URL</div>
    <div class="val" id="ac-url"></div>
    <div class="label">PIN</div>
    <div class="val pin" id="ac-pin"></div>
    <div id="ac-email-status"></div>
    <button type="button" class="btn btn-outline" onclick="copyAccess()" style="display:inline-flex;align-items:center;gap:.35rem;"><i data-lucide="clipboard" style="width:14px;height:14px;"></i> Copiar URL + PIN</button>
</div>

<h2>Proveedores invitados (<?=count($proveedores)?>)</h2>
<?php if (!$proveedores): ?>
    <div class="empty">Aún no has invitado a ningún proveedor.</div>
<?php else: ?>
<table>
    <thead><tr>
        <th>Proveedor</th><th>Invitado</th><th>Accesos</th><th>Último presupuesto</th><th>Mensajes</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($proveedores as $pv): ?>
        <tr class="<?=(int)$pv['activo']===0?'inactive':''?>">
            <td>
                <a href="admin_providers.php?proveedor_id=<?=(int)$pv['id']?>" style="color:inherit;text-decoration:none;">
                    <div class="cliente" style="text-decoration:underline;"><?=e($pv['nombre'])?></div>
                    <?php if ($pv['empresa']): ?><div style="font-size:.75rem;color:var(--text-muted);"><?=e($pv['empresa'])?></div><?php endif; ?>
                    <div style="font-size:.72rem;color:var(--text-muted);"><?=e($pv['email'])?></div>
                </a>
                <?php if ((int)$pv['ver_comentarios']===1): ?>
                    <span class="pill muted" title="Puede ver comentarios del cliente" style="display:inline-flex;align-items:center;gap:.25rem;"><i data-lucide="eye" style="width:10px;height:10px;"></i> ve cliente</span>
                <?php endif; ?>
                <?php if ((int)$pv['activo']===0): ?>
                    <span class="pill err">Revocado</span>
                <?php endif; ?>
            </td>
            <td>
                <?=fecha($pv['invited_at'])?>
            </td>
            <td>
                <?php if ((int)$pv['accesos']>0): ?>
                    <span class="pill success">✓ <?=$pv['accesos']?> vez<?=$pv['accesos']===1?'':'es'?></span>
                    <div style="font-size:.72rem;color:var(--text-muted);margin-top:.3rem;">Último: <?=fecha($pv['last_accessed_at'])?></div>
                <?php else: ?>
                    <span class="pill muted">Sin acceso</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($pv['n_presupuestos'] > 0): ?>
                    <strong>v<?=$pv['last_version']?></strong><?php if ($pv['last_importe']): ?> · <?=number_format((float)$pv['last_importe'],2,',','.')?>€<?php endif; ?>
                    <?php if ($pv['last_plazo']): ?> · <?=$pv['last_plazo']?>d<?php endif; ?>
                    <div style="font-size:.72rem;color:var(--text-muted);margin-top:.3rem;"><?=$pv['n_presupuestos']?> versión<?=$pv['n_presupuestos']===1?'':'es'?></div>
                    <?php if ($pv['last_budget_id']): ?>
                        <a href="admin_providers.php?download=<?=(int)$pv['last_budget_id']?>" target="_blank" class="btn btn-outline" style="margin-top:.3rem;display:inline-flex;align-items:center;gap:.3rem;font-size:.72rem;padding:.2rem .55rem;text-decoration:none;"><i data-lucide="file-text" style="width:12px;height:12px;"></i> Abrir PDF</a>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="pill muted">Pendiente</span>
                <?php endif; ?>
            </td>
            <td>
                <?=$pv['n_mensajes']?> mensaje<?=$pv['n_mensajes']===1?'':'s'?>
            </td>
            <td style="text-align:right;">
                <button type="button" class="btn btn-outline" onclick="copyUrl('<?=$pv['token']?>','<?=$pv['pin']?>')" style="font-size:.72rem;padding:.25rem .55rem;">Copiar</button>
                <?php if ((int)$pv['activo']===1): ?>
                    <button type="button" class="btn btn-outline" onclick="revoke(<?=$pv['id']?>)" style="font-size:.72rem;padding:.25rem .55rem;">Revocar</button>
                <?php else: ?>
                    <button type="button" class="btn btn-outline" onclick="reactivate(<?=$pv['id']?>)" style="font-size:.72rem;padding:.25rem .55rem;">Reactivar</button>
                <?php endif; ?>
                <button type="button" class="btn btn-danger" onclick="deleteProvider(<?=$pv['id']?>, '<?=htmlspecialchars(addslashes($pv['nombre']), ENT_QUOTES)?>', <?=(int)$pv['n_mensajes']?>)" style="font-size:.72rem;padding:.25rem .55rem;" title="Eliminar definitivamente">Eliminar</button>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<!-- Mensajes / hilos de proveedores -->
<h2>Mensajes de proveedores (<?= count(array_filter($mensajes, fn($m) => !$m['parent_id'])) ?>)</h2>
<?php
$roots = array_filter($mensajes, fn($m) => !$m['parent_id']);
$repliesBy = [];
foreach ($mensajes as $m) if ($m['parent_id']) { $repliesBy[$m['parent_id']][] = $m; }
if (!$roots): ?>
    <div class="empty">Sin mensajes de proveedores todavía.</div>
<?php else: foreach ($roots as $root): $replies = $repliesBy[$root['id']] ?? []; ?>
<article class="thread">
    <header class="thread-head">
        <div>
            <span class="prov"><?=e($root['proveedor_nombre'])?><?=$root['proveedor_empresa'] ? ' (' . e($root['proveedor_empresa']) . ')' : ''?></span>
            <?php if ($root['section_title']): ?> · <em><?=e($root['section_title'])?></em><?php endif; ?>
        </div>
        <div><?=fecha($root['created_at'])?></div>
    </header>
    <div class="thread-body">
        <div class="msg">
            <div class="msg-author"><?=e($root['autor_nombre'])?></div>
            <div class="msg-text"><?=e($root['texto'])?></div>
        </div>
        <?php foreach ($replies as $r): ?>
        <div class="msg reply">
            <div class="msg-author <?=$r['autor_tipo']==='staff'?'staff':''?>">
                <?=$r['autor_tipo']==='staff' ? 'Tres Puntos' : e($r['autor_nombre'])?>
                <span style="color:var(--text-muted);font-weight:400;font-size:.72rem;margin-left:.5rem;"><?=fecha($r['created_at'])?></span>
            </div>
            <div class="msg-text"><?=e($r['texto'])?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <form class="reply-form" onsubmit="return replyProviderMsg(event, <?=$root['id']?>)">
        <textarea name="texto" placeholder="Responder al proveedor…" required></textarea>
        <div class="row">
            <button type="submit" class="btn">Responder</button>
        </div>
    </form>
</article>
<?php endforeach; endif; ?>

<?php endif; ?>

</main>
</div><!-- /.admin-layout -->

<script>
if (window.lucide) lucide.createIcons();
async function inviteProvider(e) {
    e.preventDefault();
    const form = e.target;
    const fd = new FormData(form);
    fd.append('action', 'invite_provider');
    const btn = form.querySelector('button[type=submit]');
    btn.disabled = true; btn.textContent = 'Generando…';
    const r = await fetch('admin_providers.php', { method: 'POST', body: new URLSearchParams(fd) }).then(r => r.json()).catch(() => ({}));
    btn.disabled = false; btn.textContent = 'Generar acceso';
    if (!r.success) { alert(r.error || 'Error'); return false; }
    document.getElementById('ac-url').textContent = r.url;
    document.getElementById('ac-pin').textContent = r.pin;
    const emailDiv = document.getElementById('ac-email-status');
    if (r.email_sent) {
        emailDiv.innerHTML = '<div style="margin-bottom:.75rem;color:var(--mint);font-size:.85rem;"><i data-lucide="check-circle" style="width:12px;height:12px;vertical-align:-2px;"></i> Email enviado automáticamente</div>';
    } else if (r.email_error) {
        emailDiv.innerHTML = '<div style="margin-bottom:.75rem;color:#ff6b6b;font-size:.8rem;"><i data-lucide="alert-triangle" style="width:12px;height:12px;vertical-align:-2px;"></i> Email NO enviado: ' + (r.email_error || '') + ' · comparte URL+PIN manualmente.</div>';
    } else {
        emailDiv.innerHTML = '<div style="margin-bottom:.75rem;color:var(--text-muted);font-size:.8rem;">Sin email automático. Comparte URL+PIN con el proveedor.</div>';
    }
    document.getElementById('access-card').classList.add('visible');
    window.__lastAccess = r.url + '\nPIN: ' + r.pin;
    form.reset();
    form.querySelector('[name=ver_comentarios]').checked = true;
    form.querySelector('[name=send_email]').checked = true;
    setTimeout(() => location.reload(), 2500);
    return false;
}

function copyAccess() {
    if (!window.__lastAccess) return;
    navigator.clipboard.writeText(window.__lastAccess).then(() => alert('Copiado'));
}

function copyUrl(token, pin) {
    const url = window.location.origin + '/s/' + token + '\nPIN: ' + pin;
    navigator.clipboard.writeText(url).then(() => alert('Copiado'));
}

async function revoke(id) {
    if (!confirm('Revocar el acceso de este proveedor? Ya no podrá entrar con su link/PIN.')) return;
    await fetch('admin_providers.php', { method: 'POST', body: new URLSearchParams({action: 'revoke_provider', id}) });
    location.reload();
}

async function reactivate(id) {
    await fetch('admin_providers.php', { method: 'POST', body: new URLSearchParams({action: 'reactivate_provider', id}) });
    location.reload();
}

async function deleteProvider(id, nombre, nMensajes) {
    const detalles = nMensajes > 0
        ? `\n\n⚠️ Se borrarán también ${nMensajes} mensaje(s) + presupuestos PDF subidos.`
        : '\n\nSe borrarán también los presupuestos PDF que hubiera subido.';
    if (!confirm(`¿Eliminar DEFINITIVAMENTE al proveedor "${nombre}"?${detalles}\n\nEsta acción es irreversible.`)) return;
    if (!confirm(`Última confirmación: eliminar "${nombre}" sin posibilidad de recuperar.`)) return;
    const r = await fetch('admin_providers.php', { method: 'POST', body: new URLSearchParams({action: 'delete_provider', id}) }).then(r => r.json()).catch(() => ({}));
    if (r.success) {
        alert(`"${r.nombre || nombre}" eliminado.` + (r.files_deleted ? ` (${r.files_deleted} PDF(s) borrados)` : ''));
        location.reload();
    } else {
        alert(r.error || 'Error al eliminar');
    }
}

async function replyProviderMsg(e, parentId) {
    e.preventDefault();
    const ta = e.target.querySelector('textarea');
    const texto = ta.value.trim();
    if (!texto) return false;
    const r = await fetch('admin_providers.php', { method: 'POST', body: new URLSearchParams({action: 'reply_to_provider_msg', parent_id: parentId, texto}) }).then(r => r.json()).catch(() => ({}));
    if (r.success) location.reload();
    else alert(r.error || 'Error');
    return false;
}
</script>
</body></html>
