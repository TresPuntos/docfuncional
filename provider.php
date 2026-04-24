<?php
/**
 * Provider Portal — /s/{token}
 *
 * Vista restringida para proveedores invitados:
 *   - Ven el documento funcional (HTML)
 *   - (Opcional) Ven los comentarios del cliente + respuestas staff
 *   - Suben su presupuesto (PDF + importe + plazo + notas) · múltiples versiones
 *   - Pueden dejar mensajes propios sobre el documento
 *   - NO ven el presupuesto Holded ni el PDF que el cliente recibirá, ni presupuestos de otros proveedores
 */

require __DIR__ . '/config.php';
session_start();

$token = trim($_GET['token'] ?? '');
if (!preg_match('/^[a-f0-9]{24,48}$/i', $token)) {
    http_response_code(404);
    echo 'Token inválido.';
    exit;
}

$pdo = getDBConnection();
$stmt = $pdo->prepare("SELECT p.*, pr.slug, pr.client_name, pr.html_content, pr.version AS prop_version
                       FROM propuesta_proveedores p
                       JOIN propuestas pr ON pr.id = p.propuesta_id
                       WHERE p.token = ? AND p.activo = 1");
$stmt->execute([$token]);
$provider = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$provider) {
    http_response_code(404);
    echo 'Acceso no encontrado o revocado.';
    exit;
}

// Session key específica por token
$sessKey = 'provider_unlocked_' . $token;

// --- API endpoints (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api_action'])) {
    header('Content-Type: application/json; charset=utf-8');

    // Todas las acciones AJAX requieren PIN del proveedor desbloqueado O admin logueado
    if (empty($_SESSION[$sessKey]) && empty($_SESSION['admin_logged'])) {
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        exit;
    }

    $action = $_POST['api_action'];

    if ($action === 'list_messages') {
        $st = $pdo->prepare("SELECT id, section_anchor, section_title, autor_tipo, autor_nombre, texto, parent_id, resuelto, created_at
                             FROM proveedor_mensajes
                             WHERE proveedor_id = ? AND (is_draft IS NULL OR is_draft = 0)
                             ORDER BY created_at ASC");
        $st->execute([$provider['id']]);
        echo json_encode(['success' => true, 'messages' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'add_message') {
        $anchor = trim($_POST['anchor'] ?? '');
        $title = trim($_POST['section_title'] ?? '');
        $texto = trim($_POST['texto'] ?? '');
        // La firma del proveedor puede venir en el payload (identidad diferente por si se hace "cambiar")
        $firmanteNombre = trim($_POST['firmante_nombre'] ?? '') ?: $provider['nombre'];
        $parentId = isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null;
        if ($texto === '' || mb_strlen($texto) > 4000) {
            echo json_encode(['success' => false, 'error' => 'Mensaje inválido']);
            exit;
        }
        $pdo->prepare("INSERT INTO proveedor_mensajes (proveedor_id, section_anchor, section_title, autor_tipo, autor_nombre, texto, parent_id)
                       VALUES (?, ?, ?, 'proveedor', ?, ?, ?)")
            ->execute([$provider['id'], $anchor ?: null, $title ?: null, $firmanteNombre, $texto, $parentId]);
        $id = (int)$pdo->lastInsertId();

        // Telegram: avisar a Jordi
        if (defined('TELEGRAM_BOT_TOKEN') && defined('TELEGRAM_CHAT_ID')) {
            $resumen = mb_substr($texto, 0, 100) . (mb_strlen($texto) > 100 ? '…' : '');
            $msg = "💬 Mensaje proveedor · <b>" . htmlspecialchars($provider['nombre']) . "</b>"
                . ($provider['empresa'] ? ' (' . htmlspecialchars($provider['empresa']) . ')' : '')
                . "\n<b>" . htmlspecialchars($provider['client_name']) . "</b> · <i>" . htmlspecialchars($title ?: $anchor ?: 'general') . "</i>"
                . "\n" . htmlspecialchars($resumen);
            @file_get_contents("https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage?chat_id=" . TELEGRAM_CHAT_ID . "&parse_mode=HTML&text=" . urlencode($msg));
        }
        echo json_encode(['success' => true, 'id' => $id]);
        exit;
    }

    if ($action === 'delete_message') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM proveedor_mensajes WHERE id = ? AND proveedor_id = ? AND autor_tipo = 'proveedor'")
            ->execute([$id, $provider['id']]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'staff_reply') {
        // Solo admin puede responder como Tres Puntos
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
        $parent = $pdo->prepare("SELECT proveedor_id, section_anchor, section_title FROM proveedor_mensajes WHERE id = ? AND proveedor_id = ?");
        $parent->execute([$parentId, $provider['id']]);
        $p = $parent->fetch(PDO::FETCH_ASSOC);
        if (!$p) { echo json_encode(['success' => false, 'error' => 'Mensaje padre no encontrado']); exit; }

        $pdo->prepare("INSERT INTO proveedor_mensajes (proveedor_id, section_anchor, section_title, autor_tipo, autor_nombre, texto, parent_id)
                       VALUES (?, ?, ?, 'staff', 'Tres Puntos', ?, ?)")
            ->execute([$provider['id'], $p['section_anchor'], $p['section_title'], $texto, $parentId]);
        $id = (int)$pdo->lastInsertId();

        // Telegram notification
        if (defined('TELEGRAM_BOT_TOKEN') && defined('TELEGRAM_CHAT_ID')) {
            $resumen = mb_substr($texto, 0, 120) . (mb_strlen($texto) > 120 ? '…' : '');
            $msg = "✅ Respuesta a proveedor · <b>" . htmlspecialchars($provider['nombre']) . "</b>"
                . "\n<i>" . htmlspecialchars($p['section_title'] ?: $p['section_anchor'] ?: 'general') . "</i>"
                . "\n" . htmlspecialchars($resumen);
            @file_get_contents("https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage?chat_id=" . TELEGRAM_CHAT_ID . "&parse_mode=HTML&text=" . urlencode($msg));
        }

        echo json_encode(['success' => true, 'id' => $id]);
        exit;
    }

    if ($action === 'list_budgets') {
        $st = $pdo->prepare("SELECT id, archivo_nombre, archivo_size, importe_total, plazo_dias, moneda, notas, version_num, uploaded_at
                             FROM proveedor_presupuestos WHERE proveedor_id = ? ORDER BY uploaded_at DESC");
        $st->execute([$provider['id']]);
        echo json_encode(['success' => true, 'budgets' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Acción no reconocida']);
    exit;
}

// --- Upload de presupuesto (multipart) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_budget'])) {
    if (empty($_SESSION[$sessKey])) {
        http_response_code(401);
        echo 'No autorizado';
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');

    if (empty($_FILES['archivo']['tmp_name']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'Archivo no recibido correctamente']);
        exit;
    }

    $file = $_FILES['archivo'];
    if ($file['size'] > 20 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'Archivo demasiado grande (máx 20MB)']);
        exit;
    }

    // Validar tipo
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ['application/pdf', 'application/x-pdf'], true)) {
        echo json_encode(['success' => false, 'error' => 'Solo se acepta PDF (detectado: ' . $mime . ')']);
        exit;
    }

    // Destino
    $destDir = __DIR__ . '/uploads/proveedores/' . $provider['propuesta_id'];
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
    $uuid = bin2hex(random_bytes(12));
    $destPath = $destDir . '/' . $uuid . '.pdf';

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        echo json_encode(['success' => false, 'error' => 'No se pudo guardar el archivo']);
        exit;
    }

    $importe = isset($_POST['importe']) && $_POST['importe'] !== '' ? (float)$_POST['importe'] : null;
    $plazo = isset($_POST['plazo']) && $_POST['plazo'] !== '' ? (int)$_POST['plazo'] : null;
    $notas = trim($_POST['notas'] ?? '');

    // Numero de versión = N previas + 1
    $prev = $pdo->prepare("SELECT COUNT(*) FROM proveedor_presupuestos WHERE proveedor_id = ?");
    $prev->execute([$provider['id']]);
    $version = ((int)$prev->fetchColumn()) + 1;

    $pdo->prepare("INSERT INTO proveedor_presupuestos
        (proveedor_id, archivo_path, archivo_nombre, archivo_size, archivo_mime, importe_total, plazo_dias, notas, version_num)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            $provider['id'],
            'uploads/proveedores/' . $provider['propuesta_id'] . '/' . $uuid . '.pdf',
            mb_substr($file['name'], 0, 200),
            $file['size'],
            $mime,
            $importe, $plazo, $notas, $version
        ]);
    $budgetId = (int)$pdo->lastInsertId();

    // Telegram
    if (defined('TELEGRAM_BOT_TOKEN') && defined('TELEGRAM_CHAT_ID')) {
        $impFmt = $importe !== null ? number_format($importe, 2, ',', '.') . '€' : 'sin importe';
        $plazoFmt = $plazo !== null ? $plazo . ' días' : 'sin plazo';
        $msg = "🏗️ Presupuesto subido · <b>" . htmlspecialchars($provider['nombre']) . "</b>"
            . ($provider['empresa'] ? ' (' . htmlspecialchars($provider['empresa']) . ')' : '')
            . "\n<b>" . htmlspecialchars($provider['client_name']) . "</b> · v$version"
            . "\n💰 $impFmt · ⏱ $plazoFmt";
        @file_get_contents("https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage?chat_id=" . TELEGRAM_CHAT_ID . "&parse_mode=HTML&text=" . urlencode($msg));
    }

    echo json_encode(['success' => true, 'id' => $budgetId, 'version' => $version]);
    exit;
}

// --- Login gate (nombre + email + PIN) ---
$loginErrors = [];
$loginPrefill = [
    'nombre' => $provider['nombre'] ?? '',
    'email'  => $provider['email'] ?? '',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pin'])) {
    $pin    = trim($_POST['pin'] ?? '');
    $nombre = trim($_POST['visitor_nombre'] ?? '');
    $email  = trim($_POST['visitor_email'] ?? '');
    $loginPrefill = ['nombre' => $nombre, 'email' => $email];

    if ($nombre === '' || mb_strlen($nombre) < 2) {
        $loginErrors['nombre'] = 'Escribe tu nombre para continuar.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $loginErrors['email'] = 'Introduce un email válido.';
    } elseif (strcasecmp(trim($email), trim($provider['email'] ?? '')) !== 0) {
        // El email debe coincidir con el de la invitación (security: el token + PIN + email atado juntos)
        $loginErrors['email'] = 'Este email no coincide con el de la invitación. Si la invitación se envió a otra dirección, contacta con Tres Puntos.';
    }
    if (!hash_equals($provider['pin'], $pin)) {
        $loginErrors['pin'] = 'PIN incorrecto.';
    }

    if (!$loginErrors) {
        // Regenerar ID de sesión tras elevar privilegios (prevención de session fixation)
        session_regenerate_id(true);
        $_SESSION[$sessKey] = true;
        // Guardar identidad (aunque el provider ya la tenga en DB, dejamos el nombre elegido ahora por si lo editó)
        $_SESSION['provider_identity_' . $token] = [
            'nombre'      => mb_substr($nombre, 0, 120),
            'email'       => mb_substr(strtolower($email), 0, 180),
            'is_internal' => isInternalEmail($email) ? 1 : 0,
            'provider_id' => (int)$provider['id'],
        ];
        // Si el proveedor editó su nombre (p.ej. admin le invitó como "Jordi" y él firma como "Jordi Expósito"), lo persistimos
        if (trim($nombre) !== trim($provider['nombre'])) {
            $pdo->prepare("UPDATE propuesta_proveedores SET nombre = ? WHERE id = ?")
                ->execute([mb_substr($nombre, 0, 120), (int)$provider['id']]);
        }
        // Bump de acceso
        $pdo->prepare("UPDATE propuesta_proveedores SET last_accessed_at = CURRENT_TIMESTAMP, accesos = accesos + 1 WHERE id = ?")
            ->execute([$provider['id']]);

        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        // === GATE DE CONTRATOS PENDIENTES (NDA, subcontratación, etc) ===
        // Tolera que las tablas contratos/contratos_firmas aún no estén creadas en prod:
        // si fallan, seguimos el flujo normal (propuesta) sin romper al proveedor.
        try {
            $pendiente = $pdo->prepare("
                SELECT id FROM contratos
                WHERE destinatario_tipo = 'proveedor' AND destinatario_id = ?
                  AND estado IN ('enviado','visto','firmado_parcial')
                ORDER BY created_at ASC LIMIT 1
            ");
            $pendiente->execute([$provider['id']]);
            $pendienteId = $pendiente->fetchColumn();

            if ($pendienteId) {
                $checkFirma = $pdo->prepare("SELECT id FROM contratos_firmas WHERE contrato_id = ? AND rol = 'proveedor' AND firmado_at IS NULL");
                $checkFirma->execute([$pendienteId]);
                if ($checkFirma->fetchColumn()) {
                    header('Location: ' . $scheme . '://' . $host . '/provider_contrato.php?token=' . urlencode($token) . '&contrato_id=' . (int)$pendienteId);
                    exit;
                }
            }
        } catch (\Throwable $_e) {
            // Tablas de contratos no existen todavía en esta BD → seguimos sin gate
        }

        // Redirige a view.php con el token — el proveedor ve la misma vista que el cliente
        header('Location: ' . $scheme . '://' . $host . '/p/' . rawurlencode($provider['slug']) . '?__provider=' . urlencode($token));
        exit;
    }
}

$unlocked = !empty($_SESSION[$sessKey]);

// Si ya está desbloqueado y accede directamente a /s/{token}, comprobamos si tiene contratos pendientes
// antes de redirigir a /p/{slug}?__provider=
if ($unlocked && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Mismo check de contratos pendientes — defensivo por si las tablas aún no existen
    try {
        $pendiente = $pdo->prepare("
            SELECT c.id FROM contratos c
            WHERE c.destinatario_tipo = 'proveedor' AND c.destinatario_id = ?
              AND c.estado IN ('enviado','visto','firmado_parcial')
              AND EXISTS (SELECT 1 FROM contratos_firmas WHERE contrato_id = c.id AND rol = 'proveedor' AND firmado_at IS NULL)
            ORDER BY c.created_at ASC LIMIT 1
        ");
        $pendiente->execute([$provider['id']]);
        $pendienteId = $pendiente->fetchColumn();
        if ($pendienteId) {
            header('Location: ' . $scheme . '://' . $host . '/provider_contrato.php?token=' . urlencode($token) . '&contrato_id=' . (int)$pendienteId);
            exit;
        }
    } catch (\Throwable $_e) {
        // Tablas de contratos no existen todavía → seguimos al flujo normal
    }

    header('Location: ' . $scheme . '://' . $host . '/p/' . rawurlencode($provider['slug']) . '?__provider=' . urlencode($token));
    exit;
}

// Si NO desbloqueado → pantalla de PIN
if (!$unlocked):
?>
<?php
    $storageKey = 'tp_provider_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $token);
    $nomPref = htmlspecialchars($loginPrefill['nombre'] ?? '', ENT_QUOTES);
    $emlPref = htmlspecialchars($loginPrefill['email'] ?? '', ENT_QUOTES);
?>
<!doctype html>
<html lang="es"><head>
<meta charset="utf-8"><title>Acceso proveedor · Tres Puntos</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
*{box-sizing:border-box;}
body{margin:0;background:#0e0e0e;color:#f5f5f5;font-family:-apple-system,Inter,sans-serif;display:grid;place-items:center;min-height:100vh;padding:1.5rem;}
.box{background:#141414;border:1px solid #1f1f1f;padding:2.2rem 2rem;border-radius:16px;max-width:440px;width:100%;box-shadow:0 24px 60px rgba(0,0,0,.4);}
.brand{display:flex;align-items:center;gap:.55rem;margin-bottom:1.4rem;justify-content:center;}
.brand-dot{background:#5dffbf;width:22px;height:22px;border-radius:999px;}
.brand-text{font-weight:700;font-size:.95rem;}
.eyebrow{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.35em;color:rgba(255,255,255,.4);text-align:center;margin-bottom:.3rem;}
h1{margin:0 0 .25rem;font-size:1.3rem;font-weight:800;text-align:center;font-family:'Plus Jakarta Sans',sans-serif;}
p.sub{color:#8a8a8a;font-size:.85rem;margin:0 0 1.4rem;line-height:1.55;text-align:center;}
.field{margin-bottom:1rem;}
.field label{display:block;margin-bottom:.35rem;color:#8a8a8a;font-size:11px;text-transform:uppercase;letter-spacing:.08em;font-weight:600;}
.field input{width:100%;background:#191919;border:1px solid #2a2a2a;color:#fff;padding:.8rem 1rem;border-radius:10px;font-size:15px;font-family:inherit;transition:border-color .15s,background .15s;}
.field input:focus{outline:none;border-color:#5dffbf;background:#0f1511;}
.field.has-error input{border-color:#ef4444;background:rgba(239,68,68,.05);}
.field .err{color:#ff6b6b;font-size:11px;margin-top:.35rem;}
.pin-input{text-align:center;font-family:'JetBrains Mono',monospace !important;font-size:1.5rem !important;letter-spacing:.3em;color:#5dffbf !important;}
button.submit{width:100%;margin-top:.6rem;background:#5dffbf;color:#000;border:none;padding:.95rem;border-radius:10px;font-weight:800;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;text-transform:uppercase;letter-spacing:.08em;}
button.submit:hover{background:#49e6a8;}
button.not-me{display:none;width:100%;background:none;border:none;color:#8a8a8a;font-size:11px;text-decoration:underline;text-align:center;margin-top:.8rem;cursor:pointer;font-family:inherit;}
button.not-me:hover{color:#f5f5f5;}
.legal{font-size:11px;line-height:1.5;color:#666;text-align:center;margin-top:1.3rem;}
</style>
</head><body>
<div class="box">
  <div class="brand"><span class="brand-dot"></span><span class="brand-text">Tres Puntos</span></div>
  <p class="eyebrow">Acceso proveedor</p>
  <h1><?=htmlspecialchars($provider['client_name'])?></h1>
  <p class="sub">Confirma tus datos e introduce el PIN para acceder al documento.</p>

  <form method="post" autocomplete="on" novalidate>
    <div class="field<?=isset($loginErrors['nombre'])?' has-error':''?>">
      <label for="pv-nombre">Nombre</label>
      <input id="pv-nombre" type="text" name="visitor_nombre" value="<?=$nomPref?>"
             autocomplete="name" maxlength="120" required placeholder="Tu nombre">
      <?php if (isset($loginErrors['nombre'])): ?>
        <div class="err"><?=htmlspecialchars($loginErrors['nombre'])?></div>
      <?php endif; ?>
    </div>

    <div class="field<?=isset($loginErrors['email'])?' has-error':''?>">
      <label for="pv-email">Email</label>
      <input id="pv-email" type="email" name="visitor_email" value="<?=$emlPref?>"
             autocomplete="email" maxlength="180" required inputmode="email"
             placeholder="tu@empresa.com">
      <?php if (isset($loginErrors['email'])): ?>
        <div class="err"><?=htmlspecialchars($loginErrors['email'])?></div>
      <?php endif; ?>
    </div>

    <div class="field<?=isset($loginErrors['pin'])?' has-error':''?>">
      <label for="pv-pin">PIN</label>
      <input id="pv-pin" type="password" name="pin" class="pin-input"
             inputmode="numeric" autocomplete="off" maxlength="10" required
             placeholder="••••">
      <?php if (isset($loginErrors['pin'])): ?>
        <div class="err"><?=htmlspecialchars($loginErrors['pin'])?></div>
      <?php endif; ?>
    </div>

    <button type="submit" class="submit">Acceder al proyecto</button>
    <button type="button" class="not-me" id="pv-not-me" onclick="tpClearProviderIdentity()">¿No eres tú? · Cambiar datos</button>
  </form>

  <p class="legal">🔒 Solo tú puedes ver y subir presupuestos en este enlace. El resto de proveedores no aparecen.</p>
</div>

<script>
(function() {
    const KEY = <?=json_encode($storageKey)?>;
    const nameEl = document.getElementById('pv-nombre');
    const emailEl = document.getElementById('pv-email');
    const pinEl = document.getElementById('pv-pin');
    const notMe = document.getElementById('pv-not-me');

    // localStorage se usa solo como refuerzo; el server ya pre-rellena desde la invitación
    if (nameEl.value && emailEl.value) {
        // Indicar "no soy yo" por si alguien más usa el dispositivo
        notMe.style.display = 'inline-block';
        setTimeout(() => pinEl.focus(), 50);
    } else {
        try {
            const saved = JSON.parse(localStorage.getItem(KEY) || 'null');
            if (saved && saved.nombre) { nameEl.value = saved.nombre; }
            if (saved && saved.email)  { emailEl.value = saved.email; }
        } catch (e) {}
        (nameEl.value ? (emailEl.value ? pinEl : emailEl) : nameEl).focus();
    }

    document.querySelector('form').addEventListener('submit', function() {
        try {
            localStorage.setItem(KEY, JSON.stringify({
                nombre: nameEl.value.trim(),
                email: emailEl.value.trim().toLowerCase()
            }));
        } catch (e) {}
    });

    window.tpClearProviderIdentity = function() {
        try { localStorage.removeItem(KEY); } catch (e) {}
        nameEl.value = '';
        emailEl.value = '';
        notMe.style.display = 'none';
        nameEl.focus();
    };
})();
</script>
</body></html>
<?php exit; endif;

// --- Si desbloqueado → vista completa ---

// Cargar ver_comentarios: comentarios del cliente (si flag activo) — visibles pero no modificables
$clientComments = [];
if ((int)$provider['ver_comentarios'] === 1) {
    $st = $pdo->prepare("SELECT section_anchor, section_title, autor_nombre, autor_apellidos, texto, parent_id, is_staff, created_at
                         FROM comentarios_seccion
                         WHERE propuesta_id = ? AND (is_draft IS NULL OR is_draft = 0)
                         ORDER BY COALESCE(parent_id, id) ASC, created_at ASC");
    $st->execute([$provider['propuesta_id']]);
    $clientComments = $st->fetchAll(PDO::FETCH_ASSOC);
}

$htmlDoc = $provider['html_content'] ?? '';
$slug = $provider['slug'];
$clientName = $provider['client_name'];
$propVersion = $provider['prop_version'];
?>
<!doctype html>
<html lang="es" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Proveedor · <?=htmlspecialchars($clientName)?> · Tres Puntos</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/master/doc-library.css">
<script src="https://unpkg.com/lucide@latest"></script>
<style>
:root {
    --mint:#5dffbf; --mint-hover:#49e6a8; --mint-rgb:93,255,191;
    --bg-base:#0e0e0e; --bg-surface:#141414; --bg-subtle:#191919; --bg-muted:#1f1f1f; --bg-nav-hover:#1a1a1a;
    --text-primary:#f5f5f5; --text-secondary:#b3b3b3; --text-muted:#8a8a8a; --text-inverse:#0e0e0e;
    --border-base:#1f1f1f; --border-subtle:#1a1a1a; --border-strong:#2a2a2a;
    --font-heading:'Plus Jakarta Sans',sans-serif; --font-body:'Inter',system-ui,sans-serif;
    --radius-sm:6px; --radius-md:10px; --radius-lg:14px; --radius-full:9999px;
    --tp-primary:var(--mint); --tp-text-muted:var(--text-muted);
}
*{box-sizing:border-box;} body{margin:0;background:var(--bg-base);color:var(--text-primary);font:15px/1.6 var(--font-body);}
h1,h2,h3,h4{font-family:var(--font-heading);letter-spacing:-.01em;}
code{font-family:'JetBrains Mono',monospace;background:var(--bg-muted);padding:.1em .35em;border-radius:4px;font-size:.9em;}
a{color:var(--mint);}

/* Topbar */
.sv-top{position:sticky;top:0;background:var(--bg-surface);border-bottom:1px solid var(--border-base);padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;z-index:20;}
.sv-brand{display:flex;align-items:center;gap:.55rem;font-weight:700;}
.sv-brand::before{content:"";display:block;width:16px;height:16px;background:var(--mint);border-radius:999px;}
.sv-title{font-size:.95rem;}
.sv-title small{color:var(--text-muted);font-weight:400;margin-left:.4rem;}
.sv-pill{background:rgba(var(--mint-rgb),.15);color:var(--mint);padding:.2rem .6rem;border-radius:999px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;}

/* Layout */
main{max-width:1100px;margin:0 auto;padding:2rem 1.5rem 4rem;}
.sv-intro{background:linear-gradient(135deg,rgba(var(--mint-rgb),.08),rgba(var(--mint-rgb),.02));border:1px solid rgba(var(--mint-rgb),.25);border-radius:var(--radius-lg);padding:1.5rem;margin-bottom:2rem;}
.sv-intro h1{font-size:1.5rem;margin:0 0 .4rem;}
.sv-intro p{margin:.3rem 0;color:var(--text-secondary);font-size:.92rem;}

/* Upload zone */
.sv-upload{background:var(--bg-surface);border:2px dashed var(--border-strong);border-radius:var(--radius-lg);padding:1.75rem;margin-bottom:2rem;transition:border-color .15s;}
.sv-upload:hover{border-color:var(--mint);}
.sv-upload h2{margin:0 0 .3rem;font-size:1.1rem;}
.sv-upload-sub{color:var(--text-muted);font-size:.82rem;margin:0 0 1rem;}
.sv-form{display:grid;gap:.85rem;}
.sv-form .row{display:grid;grid-template-columns:1fr 1fr;gap:.75rem;}
.sv-form label{display:block;font-size:.75rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.04em;font-weight:600;margin-bottom:.3rem;}
.sv-form input[type=number],.sv-form textarea,.sv-form input[type=file]{width:100%;box-sizing:border-box;background:var(--bg-subtle);color:var(--text-primary);border:1px solid var(--border-base);padding:.6rem .75rem;border-radius:var(--radius-sm);font-family:inherit;font-size:.92rem;}
.sv-form input[type=file]{padding:.5rem;cursor:pointer;}
.sv-form input:focus,.sv-form textarea:focus{outline:none;border-color:var(--mint);}
.sv-form textarea{min-height:70px;resize:vertical;line-height:1.55;}
.sv-submit{background:var(--mint);color:#000;border:none;padding:.8rem 1.4rem;border-radius:var(--radius-sm);font-weight:700;cursor:pointer;font-family:inherit;font-size:.9rem;align-self:flex-start;justify-self:flex-start;}
.sv-submit:hover{background:var(--mint-hover);}
.sv-submit:disabled{opacity:.5;cursor:not-allowed;}

/* Mis presupuestos */
.sv-history{background:var(--bg-surface);border:1px solid var(--border-base);border-radius:var(--radius-lg);padding:1.25rem 1.5rem;margin-bottom:2rem;}
.sv-history h3{margin:0 0 .75rem;font-size:.92rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.04em;}
.sv-hist-empty{color:var(--text-muted);font-size:.85rem;padding:.5rem 0;}
.sv-hist-item{display:grid;grid-template-columns:60px 1fr auto;gap:1rem;padding:.65rem 0;border-bottom:1px dashed var(--border-base);align-items:center;font-size:.85rem;}
.sv-hist-item:last-child{border-bottom:0;}
.sv-hist-v{background:var(--mint);color:#000;padding:.1rem .45rem;border-radius:999px;font-size:.68rem;font-weight:700;text-align:center;width:fit-content;}
.sv-hist-meta{color:var(--text-secondary);font-size:.78rem;margin-top:.15rem;}

/* Documento */
.sv-doc{background:var(--bg-surface);border:1px solid var(--border-base);border-radius:var(--radius-lg);padding:2rem 2.25rem;}
.sv-doc h2,.sv-doc h3,.sv-doc h4{margin-top:2rem;}
.sv-doc h2:first-child,.sv-doc section:first-child h2{margin-top:0;}
.sv-doc section{margin-bottom:2.5rem;padding-bottom:1.5rem;border-bottom:1px solid var(--border-subtle);}
.sv-doc section:last-child{border-bottom:0;}
.sv-doc p,.sv-doc ul,.sv-doc ol{color:var(--text-primary);}

/* Sección separadora arriba */
.sv-section-title{font-size:.9rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin:2.5rem 0 .8rem;font-weight:700;}
</style>
</head>
<body>

<header class="sv-top">
    <div class="sv-brand">Tres Puntos</div>
    <div style="flex:1;text-align:center;">
        <div class="sv-title">Portal proveedor · <strong><?=htmlspecialchars($clientName)?></strong> <small>Documento funcional <?=htmlspecialchars($propVersion)?></small></div>
    </div>
    <div style="text-align:right;font-size:.75rem;color:var(--text-muted);">
        <div><strong style="color:var(--text-primary);"><?=htmlspecialchars($provider['nombre'])?></strong></div>
        <?php if ($provider['empresa']): ?><div><?=htmlspecialchars($provider['empresa'])?></div><?php endif; ?>
    </div>
</header>

<main>

<section class="sv-intro">
    <h1>Hola <?=htmlspecialchars(strtok($provider['nombre'], ' '))?>, te hemos invitado a presupuestar este proyecto</h1>
    <p>Revisa el <strong>documento funcional</strong> completo más abajo <?=($provider['ver_comentarios'] ? 'junto con los comentarios del cliente' : '')?>.</p>
    <p>Sube tu presupuesto en PDF rellenando los campos para que podamos compararlo con otros proveedores. Puedes subir varias versiones si necesitas iterar. Si tienes dudas sobre algún punto, déjanos un mensaje en la sección correspondiente.</p>
</section>

<!-- Upload -->
<section class="sv-upload">
    <h2 style="display:flex;align-items:center;gap:.55rem;"><i data-lucide="upload-cloud"></i> Subir presupuesto</h2>
    <p class="sv-upload-sub">PDF obligatorio. Los campos estructurados nos ayudan a comparar objetivamente.</p>
    <form class="sv-form" id="sv-budget-form" enctype="multipart/form-data">
        <div>
            <label for="sv-file">Archivo PDF</label>
            <input type="file" id="sv-file" name="archivo" accept="application/pdf" required>
        </div>
        <div class="row">
            <div>
                <label for="sv-importe">Importe total (€)</label>
                <input type="number" id="sv-importe" name="importe" step="0.01" min="0" placeholder="12500.00">
            </div>
            <div>
                <label for="sv-plazo">Plazo (días)</label>
                <input type="number" id="sv-plazo" name="plazo" min="1" placeholder="45">
            </div>
        </div>
        <div>
            <label for="sv-notas">Notas</label>
            <textarea id="sv-notas" name="notas" placeholder="Incluye aquí cualquier condición, exclusión o matiz…"></textarea>
        </div>
        <button type="submit" class="sv-submit">Enviar presupuesto</button>
    </form>
</section>

<!-- Historial de mis uploads -->
<section class="sv-history">
    <h3>Mis presupuestos enviados</h3>
    <div id="sv-history-list">
        <div class="sv-hist-empty">Cargando…</div>
    </div>
</section>

<!-- Documento funcional -->
<div class="sv-section-title">Documento funcional · <span style="color:var(--text-muted);text-transform:none;font-weight:400;">pulsa "💬 Comentar" junto a cualquier sección para dejar una pregunta</span></div>
<article class="sv-doc doc-view" id="content-area">
    <?= $htmlDoc ?>
</article>

<!-- Mensajes del cliente (si flag activo) -->
<?php if ((int)$provider['ver_comentarios'] === 1 && !empty($clientComments)): ?>
<div class="sv-section-title" style="margin-top:3rem;">Comentarios del cliente (histórico · para contexto)</div>
<section style="background:var(--bg-surface);border:1px solid var(--border-base);border-radius:var(--radius-lg);padding:1.5rem;">
    <?php
    $byRoot = [];
    $repliesByRoot = [];
    foreach ($clientComments as $c) {
        if (!$c['parent_id']) { $byRoot[$c['id'] ?? count($byRoot)] = $c; }
    }
    // Render simple de los hilos
    $roots = array_filter($clientComments, fn($c) => !$c['parent_id']);
    foreach ($clientComments as $c) {
        if ($c['parent_id']) { $repliesByRoot[$c['parent_id']][] = $c; }
    }
    $rootIndex = 0;
    foreach ($roots as $root):
        $rootIndex++;
        $replies = $repliesByRoot[$root['id'] ?? null] ?? [];
    ?>
        <article style="padding:.85rem 0;border-bottom:1px dashed var(--border-base);">
            <div style="font-size:.72rem;color:var(--text-muted);margin-bottom:.3rem;"><?=htmlspecialchars($root['section_title'] ?: $root['section_anchor'])?></div>
            <div style="font-weight:600;color:var(--text-primary);margin-bottom:.35rem;font-size:.88rem;"><?=htmlspecialchars(($root['autor_nombre'] ?? 'Cliente') . ' ' . ($root['autor_apellidos'] ?? ''))?></div>
            <div style="color:var(--text-secondary);font-size:.88rem;line-height:1.55;white-space:pre-wrap;"><?=htmlspecialchars($root['texto'])?></div>
            <?php foreach ($replies as $r): ?>
                <div style="margin-top:.5rem;padding:.5rem .75rem;border-left:2px solid var(--mint);background:rgba(var(--mint-rgb),.04);border-radius:4px;">
                    <div style="font-size:.72rem;color:var(--mint);font-weight:700;margin-bottom:.25rem;"><?=(int)($r['is_staff'] ?? 0) === 1 ? 'TRES PUNTOS' : htmlspecialchars($r['autor_nombre'] . ' ' . $r['autor_apellidos'])?></div>
                    <div style="color:var(--text-secondary);font-size:.85rem;line-height:1.5;white-space:pre-wrap;"><?=htmlspecialchars($r['texto'])?></div>
                </div>
            <?php endforeach; ?>
        </article>
    <?php endforeach; ?>
</section>
<?php endif; ?>

</main>

<!-- Sistema de comentarios idéntico al cliente: drawer ancho + modal central + botones inline por sección -->
<?php include __DIR__ . '/master/doc-feedback-provider.php'; ?>

<script>
(function () {
    'use strict';
    lucide && lucide.createIcons();

    async function post(action, params, file) {
        if (file) {
            const fd = new FormData();
            fd.append('upload_budget', '1');
            for (const [k, v] of Object.entries(params || {})) fd.append(k, v);
            fd.append('archivo', file);
            const r = await fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' });
            return r.json();
        }
        const body = new URLSearchParams({api_action: action, ...(params || {})});
        const r = await fetch(window.location.pathname, { method: 'POST', body, credentials: 'same-origin' });
        return r.json();
    }

    // ---- Upload budget ----
    async function loadBudgets() {
        const r = await post('list_budgets');
        const list = document.getElementById('sv-history-list');
        if (!r.success || !r.budgets || !r.budgets.length) {
            list.innerHTML = '<div class="sv-hist-empty">Todavía no has enviado ningún presupuesto.</div>';
            return;
        }
        list.innerHTML = r.budgets.map(b => {
            const fecha = new Date(b.uploaded_at.replace(' ','T') + 'Z').toLocaleString('es-ES', {day:'2-digit',month:'2-digit',year:'2-digit',hour:'2-digit',minute:'2-digit'});
            const imp = b.importe_total ? Number(b.importe_total).toLocaleString('es-ES',{minimumFractionDigits:2,maximumFractionDigits:2}) + '€' : '—';
            const plazo = b.plazo_dias ? b.plazo_dias + 'd' : '—';
            const sizeKb = b.archivo_size ? Math.round(b.archivo_size / 1024) + 'KB' : '';
            return `<div class="sv-hist-item">
                <div class="sv-hist-v">v${b.version_num}</div>
                <div>
                    <div>${esc(b.archivo_nombre)} <span style="color:var(--text-muted);font-size:.75rem;">${sizeKb}</span></div>
                    <div class="sv-hist-meta">💰 ${imp} · ⏱ ${plazo} · ${fecha}${b.notas ? ' · <em>' + esc(b.notas.slice(0,80)) + (b.notas.length > 80 ? '…' : '') + '</em>' : ''}</div>
                </div>
                <div style="color:var(--text-muted);font-size:.72rem;">✓ Enviado</div>
            </div>`;
        }).join('');
    }

    function esc(s){return (s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}

    document.getElementById('sv-budget-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const file = document.getElementById('sv-file').files[0];
        if (!file) { alert('Selecciona un PDF.'); return; }
        const importe = document.getElementById('sv-importe').value;
        const plazo = document.getElementById('sv-plazo').value;
        const notas = document.getElementById('sv-notas').value;
        const btn = e.target.querySelector('.sv-submit');
        btn.disabled = true; btn.textContent = 'Subiendo…';
        const r = await post(null, {importe, plazo, notas}, file);
        btn.disabled = false; btn.textContent = 'Enviar presupuesto';
        if (!r.success) { alert(r.error || 'Error al subir'); return; }
        document.getElementById('sv-budget-form').reset();
        alert('Presupuesto v' + r.version + ' enviado correctamente. Tres Puntos ya tiene tu propuesta.');
        loadBudgets();
    });

    // Load budgets al arrancar
    loadBudgets();
})();
</script>
</body>
</html>
