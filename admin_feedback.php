<?php
/**
 * Admin · Comentarios por sección + Firmas de aprobación
 *
 * - Lista los hilos abiertos y cerrados de cada propuesta.
 * - Permite al staff responder inline con parent_id (is_staff=1).
 * - El cierre de un hilo lo hace SOLO el autor del comentario raíz desde la vista cliente.
 *
 * Protegida por la misma ADMIN_PASSWORD del panel general.
 */

require __DIR__ . '/config.php';
session_start();

// --- Auth ---
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
        if ($_POST['admin_password'] === ADMIN_PASSWORD) {
            $_SESSION['admin_logged'] = true;
            header('Location: admin_feedback.php' . (isset($_GET['propuesta_id']) ? '?propuesta_id=' . (int)$_GET['propuesta_id'] : ''));
            exit;
        }
    }
    ?>
    <!doctype html><meta charset="utf-8"><title>Admin · Feedback</title>
    <style>body{background:#0e0e0e;color:#f5f5f5;font-family:system-ui;display:grid;place-items:center;height:100vh;margin:0}form{background:#141414;padding:2rem;border-radius:12px;border:1px solid #1f1f1f;display:grid;gap:.75rem;width:320px}input{background:#191919;border:1px solid #1f1f1f;color:#fff;padding:.6rem;border-radius:6px}button{background:#5dffbf;color:#000;border:none;padding:.6rem;border-radius:6px;font-weight:700;cursor:pointer}</style>
    <form method="post"><strong>Admin Feedback</strong><input name="admin_password" type="password" placeholder="Contraseña" autofocus><button>Entrar</button></form>
    <?php exit;
}

$pdo = getDBConnection();

// --- POST actions (JSON) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // Helper: notificar Telegram
    $notifyTelegram = function(array $prop, array $parent, string $texto, string $headline) {
        if (!defined('TELEGRAM_BOT_TOKEN') || !defined('TELEGRAM_CHAT_ID')) return;
        $resumen = mb_substr($texto, 0, 120) . (mb_strlen($texto) > 120 ? '…' : '');
        $msg = $headline . " · <b>" . htmlspecialchars($prop['client_name'] ?? '', ENT_QUOTES) . "</b>"
            . "\n<i>" . htmlspecialchars($parent['section_title'] ?: $parent['section_anchor'], ENT_QUOTES) . "</i>"
            . "\n" . htmlspecialchars($resumen, ENT_QUOTES);
        @file_get_contents("https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage?chat_id=" . TELEGRAM_CHAT_ID . "&parse_mode=HTML&text=" . urlencode($msg));
    };

    if ($_POST['action'] === 'add_staff_reply') {
        $parentId = (int)($_POST['parent_id'] ?? 0);
        $texto = trim($_POST['texto'] ?? '');
        // Desde la web, por defecto publicamos directamente; si viene publish=0 queda en borrador
        $isDraft = isset($_POST['publish']) && $_POST['publish'] === '0' ? 1 : 0;
        if (!$parentId || $texto === '' || mb_strlen($texto) > 4000) {
            echo json_encode(['success' => false, 'error' => 'Datos inválidos']);
            exit;
        }
        $parent = $pdo->prepare("SELECT propuesta_id, section_anchor, section_title FROM comentarios_seccion WHERE id = ?");
        $parent->execute([$parentId]);
        $p = $parent->fetch(PDO::FETCH_ASSOC);
        if (!$p) { echo json_encode(['success' => false, 'error' => 'Comentario no encontrado']); exit; }

        $pdo->prepare("INSERT INTO comentarios_seccion
            (propuesta_id, section_anchor, section_title, autor_nombre, autor_apellidos, autor_email, texto, parent_id, is_staff, is_draft, ip_address, user_agent)
            VALUES (?, ?, ?, 'Tres Puntos', '', 'hola@trespuntoscomunicacion.es', ?, ?, 1, ?, ?, ?)")
            ->execute([
                $p['propuesta_id'], $p['section_anchor'], $p['section_title'],
                $texto, $parentId, $isDraft,
                $_SERVER['REMOTE_ADDR'] ?? null,
                mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        $id = (int)$pdo->lastInsertId();

        if (!$isDraft) {
            $propQ = $pdo->prepare("SELECT slug, client_name FROM propuestas WHERE id = ?");
            $propQ->execute([$p['propuesta_id']]);
            $prop = $propQ->fetch(PDO::FETCH_ASSOC) ?: [];
            $notifyTelegram($prop, $p, $texto, "✅ Respuesta Tres Puntos");
        }

        echo json_encode(['success' => true, 'id' => $id, 'is_draft' => $isDraft]);
        exit;
    }

    if ($_POST['action'] === 'publish_reply') {
        $id = (int)($_POST['id'] ?? 0);
        $texto = trim($_POST['texto'] ?? '');  // admite edición antes de publicar
        $row = $pdo->prepare("SELECT c.texto AS current_texto, c.propuesta_id, c.section_anchor, c.section_title, c.is_draft, p.slug, p.client_name
            FROM comentarios_seccion c JOIN propuestas p ON p.id = c.propuesta_id WHERE c.id = ? AND c.is_staff = 1");
        $row->execute([$id]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        if (!$r) { echo json_encode(['success' => false, 'error' => 'Respuesta no encontrada']); exit; }
        if (!$r['is_draft']) { echo json_encode(['success' => false, 'error' => 'Ya estaba publicada']); exit; }
        $textoFinal = $texto !== '' ? $texto : $r['current_texto'];
        if (mb_strlen($textoFinal) > 4000) { echo json_encode(['success' => false, 'error' => 'Texto demasiado largo']); exit; }
        $pdo->prepare("UPDATE comentarios_seccion SET texto = ?, is_draft = 0 WHERE id = ?")->execute([$textoFinal, $id]);
        $notifyTelegram(
            ['slug' => $r['slug'], 'client_name' => $r['client_name']],
            ['section_anchor' => $r['section_anchor'], 'section_title' => $r['section_title']],
            $textoFinal, "✅ Publicada"
        );
        echo json_encode(['success' => true]);
        exit;
    }

    if ($_POST['action'] === 'update_draft') {
        $id = (int)($_POST['id'] ?? 0);
        $texto = trim($_POST['texto'] ?? '');
        if ($texto === '' || mb_strlen($texto) > 4000) { echo json_encode(['success' => false, 'error' => 'Texto inválido']); exit; }
        $stmt = $pdo->prepare("UPDATE comentarios_seccion SET texto = ? WHERE id = ? AND is_staff = 1 AND is_draft = 1");
        $stmt->execute([$texto, $id]);
        echo json_encode(['success' => $stmt->rowCount() > 0]);
        exit;
    }

    if ($_POST['action'] === 'discard_draft') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM comentarios_seccion WHERE id = ? AND is_staff = 1 AND is_draft = 1");
        $stmt->execute([$id]);
        echo json_encode(['success' => $stmt->rowCount() > 0]);
        exit;
    }

    if ($_POST['action'] === 'delete_reply') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM comentarios_seccion WHERE id = ? AND is_staff = 1");
        $stmt->execute([$id]);
        echo json_encode(['success' => $stmt->rowCount() > 0]);
        exit;
    }

    if ($_POST['action'] === 'mark_notified') {
        $propId = (int)($_POST['propuesta_id'] ?? 0);
        if (!$propId) { echo json_encode(['success' => false, 'error' => 'Falta propuesta_id']); exit; }
        $stmt = $pdo->prepare("UPDATE comentarios_seccion
            SET notificado_at = CURRENT_TIMESTAMP
            WHERE propuesta_id = ? AND is_staff = 1 AND is_draft = 0 AND notificado_at IS NULL");
        $stmt->execute([$propId]);
        echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
        exit;
    }

    if ($_POST['action'] === 'send_version_announcement') {
        $propId = (int)($_POST['propuesta_id'] ?? 0);
        $toEmail = trim($_POST['to'] ?? '');
        $customChanges = trim($_POST['changes'] ?? '');
        if (!$propId || !$toEmail || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Propuesta o email inválido']);
            exit;
        }
        if (!defined('RESEND_API_KEY') || !RESEND_API_KEY) {
            echo json_encode(['success' => false, 'error' => 'Resend no configurado']);
            exit;
        }

        $propQ = $pdo->prepare("SELECT id, slug, client_name, version FROM propuestas WHERE id = ?");
        $propQ->execute([$propId]);
        $prop = $propQ->fetch(PDO::FETCH_ASSOC);
        if (!$prop) { echo json_encode(['success' => false, 'error' => 'Propuesta no encontrada']); exit; }

        // Recupera nombre del cliente que comentó (para personalizar saludo)
        $authorQ = $pdo->prepare("SELECT autor_nombre FROM comentarios_seccion WHERE propuesta_id = ? AND parent_id IS NULL AND is_staff = 0 ORDER BY created_at ASC LIMIT 1");
        $authorQ->execute([$propId]);
        $clientFirstName = $authorQ->fetchColumn() ?: 'equipo';

        $host = $_SERVER['HTTP_HOST'] ?? 'doc.trespuntos-lab.com';
        $scheme = (($_SERVER['HTTPS'] ?? '') === 'on' || ($host !== 'localhost:8000' && strpos($host, 'localhost') === false)) ? 'https' : 'http';
        $viewUrl = $scheme . '://' . $host . '/p/' . rawurlencode($prop['slug']);
        $version = htmlspecialchars($prop['version'] ?: 'nueva versión');

        // Bullets por defecto (editables por el admin antes de enviar via $customChanges)
        $bulletsHtml = '';
        if ($customChanges !== '') {
            foreach (preg_split("/\r?\n/", $customChanges) as $line) {
                $line = trim($line);
                if ($line !== '') $bulletsHtml .= '<li style="margin: .3rem 0; color:#444;">' . htmlspecialchars(ltrim($line, '-•* '), ENT_QUOTES) . '</li>';
            }
        }

        $htmlBody = <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light only">
<meta name="supported-color-schemes" content="light only">
<title>Tres Puntos — Nueva versión del documento funcional</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Inter, Helvetica, Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 24px; color: #0e0e0e;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f5f5f5;">
  <tr><td align="center">
    <table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" style="max-width:560px; background:#ffffff; border-radius:12px; border:1px solid #e5e5e5;">

      <tr><td align="center" style="padding: 32px 32px 8px;">
        <img src="https://doc.trespuntos-lab.com/logo-trespuntos.svg" alt="Tres Puntos" width="120" height="46" style="display:block; width:120px; height:auto; border:0; outline:none;">
      </td></tr>

      <tr><td style="padding: 18px 32px 8px;">
        <span style="display:inline-block;background:rgba(93,255,191,.18);color:#098c5b;padding:.2rem .7rem;border-radius:999px;font-size:.7rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;margin-bottom:12px;">Documento funcional · {$version}</span>
        <h1 style="font-family: 'Plus Jakarta Sans', -apple-system, sans-serif; font-size: 22px; font-weight: 700; margin: 6px 0 14px; color:#0e0e0e;">Hola {$clientFirstName},</h1>
        <p style="font-size: 15px; line-height: 1.6; color:#333; margin: 0 0 16px;">
          Hemos publicado la <strong>{$version}</strong> del documento funcional con todos los ajustes que hemos consensuado en la última ronda de comentarios. Desde aquí la puedes revisar:
        </p>

HTML;

        if ($bulletsHtml !== '') {
            $htmlBody .= '<p style="font-size: 13px; color:#666; margin: 18px 0 6px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em;">Cambios principales</p>';
            $htmlBody .= '<ul style="padding-left: 20px; margin: 0 0 22px; color:#444; font-size: 14px; line-height: 1.55;">' . $bulletsHtml . '</ul>';
        }

        $htmlBody .= <<<HTML
      </td></tr>

      <tr><td align="center" style="padding: 8px 32px 28px;">
        <!--[if mso]>
        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{$viewUrl}" style="height:46px;v-text-anchor:middle;width:240px;" arcsize="17%" stroke="f" fillcolor="#0e0e0e">
          <w:anchorlock/>
          <center style="color:#5dffbf;font-family:sans-serif;font-size:15px;font-weight:bold;">Revisar {$version}</center>
        </v:roundrect>
        <![endif]-->
        <!--[if !mso]><!-- -->
        <a href="{$viewUrl}" style="background:#0e0e0e; color:#5dffbf !important; padding: 14px 30px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 15px; display: inline-block; font-family: -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif; mso-hide:all;">
          Revisar {$version} →
        </a>
        <!--<![endif]-->
      </td></tr>

      <tr><td style="padding: 0 32px 24px;">
        <p style="font-size: 13px; color:#888; line-height: 1.55; margin: 0;">
          Cuando hayas revisado y si el alcance te encaja, pasamos al <strong style="color:#0e0e0e;">presupuesto detallado por fases</strong>. Si hay algún matiz, deja el comentario directamente en la sección correspondiente del documento y lo iteramos.
        </p>
      </td></tr>

    </table>

    <!-- Firma corporativa Tres Puntos -->
    <table cellpadding="0" cellspacing="0" border="0" role="presentation" style="background:none;border:0;margin:24px auto 0;padding:0;background-color:#ffffff;">
      <tr>
        <td valign="top" style="width:190px;padding:0 12px 0 0;border-right:3px solid #5DFFBF;">
          <img src="http://trespuntoscomunicacion.es//img_firma/new-logo.jpg" alt="Tres Puntos" style="display:block;max-width:180px;height:auto;border:0;">
        </td>
        <td style="padding:0 0 0 12px;">
          <table cellpadding="0" cellspacing="0" border="0" role="presentation" style="background:none;border:0;margin:0;padding:0;">
            <tr><td colspan="2" style="padding-bottom:5px;color:#5DFFBF;font-size:18px;font-weight:800;font-family:Oswald,Helvetica,sans-serif;">Tres Puntos | Agencia UX/UI y Arquitectura web</td></tr>
            <tr><td colspan="2" style="padding-bottom:5px;color:#2A2A2A;font-size:16px;font-family:Oswald,Helvetica,sans-serif;">Jordan | Asistente IA · Tres Puntos</td></tr>
            <tr><td colspan="2" style="padding-bottom:5px;color:#2A2A2A;font-size:16px;font-family:Oswald,Helvetica,sans-serif;">Email: <a href="mailto:jordan@trespuntos-lab.com" style="color:#5DFFBF;text-decoration:none;font-weight:normal;font-size:16px;">jordan@trespuntos-lab.com</a></td></tr>
            <tr><td colspan="2" style="padding-bottom:5px;color:#2A2A2A;font-size:16px;font-family:Oswald,Helvetica,sans-serif;">Web: <a href="https://www.trespuntoscomunicacion.es" style="color:#5DFFBF;text-decoration:none;font-weight:normal;font-size:16px;">www.trespuntoscomunicacion.es</a></td></tr>
            <tr><td colspan="2" style="padding-bottom:5px;color:#2A2A2A;font-size:16px;font-family:Oswald,Helvetica,sans-serif;">Responde a este email y le llegará a <a href="mailto:jordi@trespuntoscomunicacion.es" style="color:#2A2A2A;text-decoration:none;font-size:16px;">Jordi</a></td></tr>
            <tr><td colspan="2" style="padding-bottom:5px;color:#2A2A2A;font-size:16px;font-family:Oswald,Helvetica,sans-serif;">¿Nos reunimos? <a href="https://calendly.com/trespuntos/tres-puntos" target="_blank" style="color:#5DFFBF;text-decoration:none;">Reserva cita ahora</a></td></tr>
          </table>
        </td>
      </tr>
    </table>

  </td></tr>
</table>
</body></html>
HTML;

        $textBody = "Hola $clientFirstName,\n\n"
            . "Hemos publicado la $version del documento funcional con los ajustes que hemos consensuado.\n\n"
            . ($customChanges !== '' ? "Cambios principales:\n$customChanges\n\n" : '')
            . "Revísala aquí: $viewUrl\n\n"
            . "Cuando hayas revisado y si el alcance te encaja, pasamos al presupuesto detallado por fases.\n\n"
            . "Un saludo,\nJordan · Tres Puntos Comunicación";

        $payload = [
            'from' => defined('RESEND_FROM') ? RESEND_FROM : 'Tres Puntos <onboarding@resend.dev>',
            'to' => [$toEmail],
            'subject' => $version . ' del documento funcional lista · ' . $prop['client_name'],
            'html' => $htmlBody,
            'text' => $textBody,
        ];
        if (defined('RESEND_REPLY_TO') && RESEND_REPLY_TO) $payload['reply_to'] = [RESEND_REPLY_TO];
        if (defined('CLIENT_NOTIFY_CC') && CLIENT_NOTIFY_CC && strcasecmp(CLIENT_NOTIFY_CC, $toEmail) !== 0) {
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
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            echo json_encode(['success' => false, 'error' => 'Resend ' . $httpCode . ': ' . substr($resp, 0, 300)]);
            exit;
        }

        $respData = json_decode($resp, true);
        if (defined('TELEGRAM_BOT_TOKEN') && defined('TELEGRAM_CHAT_ID')) {
            $msg = "📢 Aviso nueva versión enviado a <b>" . htmlspecialchars($toEmail) . "</b>"
                . "\n<b>" . htmlspecialchars($prop['client_name']) . "</b> · " . htmlspecialchars($version);
            @file_get_contents("https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage?chat_id=" . TELEGRAM_CHAT_ID . "&parse_mode=HTML&text=" . urlencode($msg));
        }

        echo json_encode(['success' => true, 'version' => $version, 'resend_id' => $respData['id'] ?? null]);
        exit;
    }

    if ($_POST['action'] === 'send_notification') {
        $propId = (int)($_POST['propuesta_id'] ?? 0);
        $toEmail = trim($_POST['to'] ?? '');
        if (!$propId || !$toEmail || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Propuesta o email inválido']);
            exit;
        }
        if (!defined('RESEND_API_KEY') || !RESEND_API_KEY) {
            echo json_encode(['success' => false, 'error' => 'Resend no configurado (falta RESEND_API_KEY en config.local.php)']);
            exit;
        }

        // Recoge datos de la propuesta y de las respuestas pendientes
        $propQ = $pdo->prepare("SELECT id, slug, client_name FROM propuestas WHERE id = ?");
        $propQ->execute([$propId]);
        $prop = $propQ->fetch(PDO::FETCH_ASSOC);
        if (!$prop) { echo json_encode(['success' => false, 'error' => 'Propuesta no encontrada']); exit; }

        $pendingQ = $pdo->prepare("SELECT c.section_title, c.section_anchor, c.texto, c.created_at,
                                          r.autor_nombre AS client_autor_nombre
                                   FROM comentarios_seccion c
                                   LEFT JOIN comentarios_seccion r ON r.id = c.parent_id
                                   WHERE c.propuesta_id = ? AND c.is_staff = 1 AND c.is_draft = 0 AND c.notificado_at IS NULL
                                   ORDER BY c.created_at ASC");
        $pendingQ->execute([$propId]);
        $pending = $pendingQ->fetchAll(PDO::FETCH_ASSOC);

        if (!count($pending)) {
            echo json_encode(['success' => false, 'error' => 'No hay respuestas pendientes de notificar']);
            exit;
        }

        $host = $_SERVER['HTTP_HOST'] ?? 'doc.trespuntos-lab.com';
        $scheme = (($_SERVER['HTTPS'] ?? '') === 'on' || ($host !== 'localhost:8000' && strpos($host, 'localhost') === false)) ? 'https' : 'http';
        $viewUrl = $scheme . '://' . $host . '/p/' . rawurlencode($prop['slug']);

        $clientFirstName = $pending[0]['client_autor_nombre'] ?: 'equipo';
        $count = count($pending);
        $pluralS = $count === 1 ? '' : 's';

        // Dedup por sección — si hay varias respuestas a la misma sección, solo una línea
        $sectionsList = '';
        $sectionsText = '';
        $seenSections = [];
        foreach ($pending as $p) {
            $key = $p['section_title'] ?: $p['section_anchor'];
            if (isset($seenSections[$key])) continue;
            $seenSections[$key] = true;
            $title = htmlspecialchars($key, ENT_QUOTES);
            $sectionsList .= '<li style="margin: .35rem 0; color:#444;">' . $title . '</li>';
            $sectionsText .= '· ' . $key . "\n";
        }

        // Bulletproof button con VML para Outlook + evitar inversión en Apple Mail dark
        $htmlBody = <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light only">
<meta name="supported-color-schemes" content="light only">
<title>Tres Puntos — Respuesta al documento funcional</title>
<style>
  :root { color-scheme: light only; supported-color-schemes: light only; }
  body { margin: 0 !important; padding: 0 !important; background: #f5f5f5 !important; }
  /* Evita que Gmail/Outlook aplique dark mode a nuestros fondos */
  u ~ div .email-card { background: #ffffff !important; }
  u ~ div .cta-btn { background: #0e0e0e !important; color: #5dffbf !important; }
  [data-ogsc] .email-card, [data-ogsb] .email-card { background: #ffffff !important; color: #0e0e0e !important; }
  [data-ogsc] .cta-btn, [data-ogsb] .cta-btn { background: #0e0e0e !important; color: #5dffbf !important; }
</style>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Inter, Helvetica, Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 24px; color: #0e0e0e;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f5f5f5;">
  <tr><td align="center">
    <table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" class="email-card" style="max-width:560px; background:#ffffff; border-radius:12px; border:1px solid #e5e5e5;">

      <!-- Logo -->
      <tr><td align="center" style="padding: 32px 32px 8px;">
        <img src="https://doc.trespuntos-lab.com/logo-trespuntos.svg" alt="Tres Puntos" width="120" height="46" style="display:block; width:120px; height:auto; border:0; outline:none;">
      </td></tr>

      <!-- Contenido -->
      <tr><td style="padding: 18px 32px 8px;">
        <h1 style="font-family: 'Plus Jakarta Sans', -apple-system, sans-serif; font-size: 22px; font-weight: 700; margin: 0 0 14px; color:#0e0e0e;">Hola {$clientFirstName},</h1>
        <p style="font-size: 15px; line-height: 1.6; color:#333; margin: 0 0 16px;">
          Hemos respondido a los comentarios que dejasteis en el documento funcional. Tenéis <strong style="color:#0e0e0e;">{$count} respuesta{$pluralS}</strong> nueva{$pluralS} esperando revisión.
        </p>
        <p style="font-size: 13px; color:#666; margin: 18px 0 6px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em;">Secciones con respuesta</p>
        <ul style="padding-left: 20px; margin: 0 0 22px; color:#444; font-size: 14px; line-height: 1.55;">{$sectionsList}</ul>
      </td></tr>

      <!-- Botón CTA bulletproof (negro con texto mint — aguanta dark mode) -->
      <tr><td align="center" style="padding: 8px 32px 28px;">
        <!--[if mso]>
        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{$viewUrl}" style="height:46px;v-text-anchor:middle;width:220px;" arcsize="17%" stroke="f" fillcolor="#0e0e0e">
          <w:anchorlock/>
          <center style="color:#5dffbf;font-family:sans-serif;font-size:15px;font-weight:bold;">Revisar respuestas</center>
        </v:roundrect>
        <![endif]-->
        <!--[if !mso]><!-- -->
        <a href="{$viewUrl}" class="cta-btn" style="background:#0e0e0e; color:#5dffbf !important; padding: 14px 30px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 15px; display: inline-block; font-family: -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif; mso-hide:all;">
          Revisar respuestas →
        </a>
        <!--<![endif]-->
      </td></tr>

      <!-- Nota -->
      <tr><td style="padding: 0 32px 16px;">
        <p style="font-size: 13px; color:#888; line-height: 1.55; margin: 0;">
          Lee las respuestas y, si alguna os encaja, marca el hilo como resuelto desde el documento. Cualquier matiz, responde por aquí y seguimos afinando.
        </p>
      </td></tr>

    </table>

    <!-- Firma corporativa Tres Puntos -->
    <table cellpadding="0" cellspacing="0" border="0" role="presentation" style="background:none;border:0;margin:24px auto 0;padding:0;background-color:#ffffff;">
      <tr>
        <td valign="top" style="width:190px;padding:0 12px 0 0;border-right:3px solid #5DFFBF;">
          <img src="http://trespuntoscomunicacion.es//img_firma/new-logo.jpg" alt="Tres Puntos" style="display:block;max-width:180px;height:auto;border:0;">
        </td>
        <td style="padding:0 0 0 12px;">
          <table cellpadding="0" cellspacing="0" border="0" role="presentation" style="background:none;border:0;margin:0;padding:0;">
            <tr><td colspan="2" style="padding-bottom:5px;color:#5DFFBF;font-size:18px;font-weight:800;font-family:Oswald,Helvetica,sans-serif;">Tres Puntos | Agencia UX/UI y Arquitectura web</td></tr>
            <tr><td colspan="2" style="padding-bottom:5px;color:#2A2A2A;font-size:16px;font-family:Oswald,Helvetica,sans-serif;">Jordan | Asistente IA · Tres Puntos</td></tr>
            <tr><td colspan="2" style="padding-bottom:5px;color:#2A2A2A;font-size:16px;font-family:Oswald,Helvetica,sans-serif;">Email: <a href="mailto:jordan@trespuntos-lab.com" style="color:#5DFFBF;text-decoration:none;font-weight:normal;font-size:16px;">jordan@trespuntos-lab.com</a></td></tr>
            <tr><td colspan="2" style="padding-bottom:5px;color:#2A2A2A;font-size:16px;font-family:Oswald,Helvetica,sans-serif;">Web: <a href="https://www.trespuntoscomunicacion.es" style="color:#5DFFBF;text-decoration:none;font-weight:normal;font-size:16px;">www.trespuntoscomunicacion.es</a></td></tr>
            <tr><td colspan="2" style="padding-bottom:5px;color:#2A2A2A;font-size:16px;font-family:Oswald,Helvetica,sans-serif;">Responde a este email y le llegará a <a href="mailto:jordi@trespuntoscomunicacion.es" style="color:#2A2A2A;text-decoration:none;font-size:16px;">Jordi</a></td></tr>
            <tr><td colspan="2" style="padding-bottom:5px;color:#2A2A2A;font-size:16px;font-family:Oswald,Helvetica,sans-serif;">¿Nos reunimos? <a href="https://calendly.com/trespuntos/tres-puntos" target="_blank" style="color:#5DFFBF;text-decoration:none;">Reserva cita ahora</a></td></tr>
          </table>
        </td>
      </tr>
      <tr><td colspan="2">&nbsp;</td></tr>
      <tr>
        <td colspan="2">
          <table cellpadding="0" cellspacing="0" border="0" role="presentation" style="background:none;border:0;margin:0;padding:0;">
            <tr>
              <td valign="middle" style="padding:5px 20px;background-color:#FFFFFF;color:#2A2A2A;font-size:10px;font-weight:normal;font-style:normal;font-family:Oswald,Helvetica,sans-serif;line-height:1.4;">
                Le informamos, como destinatario de este mensaje, que el correo electrónico y las comunicaciones por medio de Internet no permiten asegurar ni garantizar la confidencialidad de los mensajes transmitidos, así como tampoco su integridad o su correcta recepción, por lo que el emisor no asume responsabilidad alguna por tales circunstancias. Si no consintiese en la utilización del correo electrónico o de las comunicaciones vía Internet le rogamos nos lo comunique de manera inmediata. Este mensaje va dirigido, de manera exclusiva, a su destinatario y contiene información confidencial y sujeta al secreto profesional, cuya divulgación no está permitida por la ley. En caso de haber recibido este mensaje por error, le rogamos que, de forma inmediata, nos lo comunique mediante correo electrónico remitido a nuestra atención a info@trespuntoscomunicacion.es y proceda a su eliminación, así como a la de cualquier documento adjunto al mismo. Asimismo, le comunicamos que la distribución, copia o utilización de este mensaje, o de cualquier documento adjunto al mismo, cualquiera que fuera su finalidad, están prohibidas por la Ley.
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>

  </td></tr>
</table>
</body></html>
HTML;

        $textBody = "Hola $clientFirstName,\n\n"
            . "Hemos respondido a $count comentario$pluralS que dejasteis en el documento funcional.\n\n"
            . "Secciones con respuesta:\n$sectionsText\n"
            . "Revisa las respuestas aquí:\n$viewUrl\n\n"
            . "Un saludo,\nClaudio · Tres Puntos Comunicación";

        $payload = [
            'from' => defined('RESEND_FROM') ? RESEND_FROM : 'Tres Puntos <onboarding@resend.dev>',
            'to' => [$toEmail],
            'subject' => "Hemos respondido a tus comentarios · " . $prop['client_name'],
            'html' => $htmlBody,
            'text' => $textBody,
        ];
        if (defined('RESEND_REPLY_TO') && RESEND_REPLY_TO) $payload['reply_to'] = [RESEND_REPLY_TO];
        if (defined('CLIENT_NOTIFY_CC') && CLIENT_NOTIFY_CC && strcasecmp(CLIENT_NOTIFY_CC, $toEmail) !== 0) {
            $payload['cc'] = [CLIENT_NOTIFY_CC];
        }

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . RESEND_API_KEY,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            $errMsg = $curlErr ?: ('Resend respondió ' . $httpCode . ': ' . substr($resp, 0, 300));
            echo json_encode(['success' => false, 'error' => $errMsg]);
            exit;
        }

        $respData = json_decode($resp, true);
        // Marcar como notificadas
        $upd = $pdo->prepare("UPDATE comentarios_seccion
            SET notificado_at = CURRENT_TIMESTAMP
            WHERE propuesta_id = ? AND is_staff = 1 AND is_draft = 0 AND notificado_at IS NULL");
        $upd->execute([$propId]);

        // Telegram
        if (defined('TELEGRAM_BOT_TOKEN') && defined('TELEGRAM_CHAT_ID')) {
            $msg = "✉️ Email enviado a <b>" . htmlspecialchars($toEmail) . "</b>"
                . "\n" . $count . " respuesta" . $pluralS . " · <b>" . htmlspecialchars($prop['client_name']) . "</b>";
            @file_get_contents("https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage?chat_id=" . TELEGRAM_CHAT_ID . "&parse_mode=HTML&text=" . urlencode($msg));
        }

        echo json_encode(['success' => true, 'sent' => $count, 'resend_id' => $respData['id'] ?? null]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Acción no reconocida']);
    exit;
}

// --- Query data ---
$filterPropuesta = isset($_GET['propuesta_id']) ? (int)$_GET['propuesta_id'] : 0;

$propuestas = $pdo->query("SELECT id, slug, client_name, version FROM propuestas ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

$where = $filterPropuesta > 0 ? "WHERE c.propuesta_id = " . $filterPropuesta : "";
$comentarios = $pdo->query("
    SELECT c.*, p.slug, p.client_name
    FROM comentarios_seccion c
    LEFT JOIN propuestas p ON p.id = c.propuesta_id
    $where
    ORDER BY COALESCE(c.parent_id, c.id) ASC, c.created_at ASC
    LIMIT 1000
")->fetchAll(PDO::FETCH_ASSOC);

// Agrupa: roots + replies por parent_id
$roots = [];
$repliesByParent = [];
foreach ($comentarios as $c) {
    if ($c['parent_id']) {
        $repliesByParent[$c['parent_id']][] = $c;
    } else {
        $roots[] = $c;
    }
}

$whereAprob = $filterPropuesta > 0 ? "WHERE a.propuesta_id = " . $filterPropuesta : "WHERE a.firmante_nombre IS NOT NULL";
$aprobaciones = $pdo->query("
    SELECT a.*, p.slug, p.client_name
    FROM aprobaciones a
    LEFT JOIN propuestas p ON p.id = a.propuesta_id
    $whereAprob
    ORDER BY a.aprobado_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fecha($d) { return $d ? date('d/m/Y H:i', strtotime($d)) : '—'; }

$totalAbiertos = 0;
foreach ($roots as $r) if (!$r['resuelto']) $totalAbiertos++;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Admin · Comentarios y firmas</title>
<style>
:root {
    --mint: #5dffbf; --mint-rgb: 93, 255, 191;
    --bg-base: #0e0e0e; --bg-surface: #141414; --bg-subtle: #191919; --bg-muted: #1f1f1f;
    --text-primary: #f5f5f5; --text-secondary: #b3b3b3; --text-muted: #8a8a8a;
    --border-base: #1f1f1f; --border-strong: #2a2a2a;
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg-base); color: var(--text-primary); font: 14px/1.5 system-ui, sans-serif; }
header { padding: 1.25rem 2rem; border-bottom: 1px solid var(--border-base); background: var(--bg-surface); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; position:sticky; top:0; z-index:10; }
h1 { margin: 0; font-size: 1.15rem; }
.header-left { display:flex; align-items:center; gap:.9rem; flex-wrap:wrap; }
.badge-global { background: rgba(var(--mint-rgb), .15); color: var(--mint); padding: .35rem .75rem; border-radius: 999px; font-size: .78rem; font-weight: 600; }
.badge-global.zero { background: var(--bg-muted); color: var(--text-muted); }
header a { color: var(--mint); text-decoration: none; }
main { padding: 1.5rem 2rem; display: grid; gap: 2rem; max-width: 1280px; }
h2 { font-size: 1rem; color: var(--text-secondary); letter-spacing:.04em; text-transform: uppercase; margin: 0 0 .75rem; border-bottom: 1px solid var(--border-base); padding-bottom: .5rem; }
select { background: var(--bg-subtle); border: 1px solid var(--border-base); color: var(--text-primary); padding: .4rem .6rem; border-radius: 6px; }
.empty { color: var(--text-muted); padding: 2rem; text-align: center; background: var(--bg-surface); border-radius: 8px; }
.filter { display: flex; gap: .75rem; align-items: center; }

/* Tabla de firmas (se mantiene compacta) */
table { width: 100%; border-collapse: collapse; background: var(--bg-surface); border-radius: 8px; overflow: hidden; }
table th, table td { padding: .65rem .85rem; text-align: left; border-bottom: 1px solid var(--border-base); vertical-align: top; font-size: .82rem; }
table th { background: var(--bg-subtle); color: var(--text-secondary); font-weight: 600; font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; }
table tr:last-child td { border-bottom: 0; }
.cliente { color: var(--mint); font-weight: 600; }
.section-tag { display: inline-block; background: var(--bg-muted); color: var(--text-secondary); padding: .15rem .5rem; border-radius: 999px; font-size: .7rem; font-family: monospace; }
.hash { font-family: monospace; color: var(--text-muted); font-size: .72rem; }
.pill { display:inline-block; padding:.15rem .55rem; border-radius:999px; font-size:.7rem; font-weight:600; }
.pill.doc { background: rgba(93,255,191,.15); color: var(--mint); }
.pill.pdf { background: rgba(123,150,255,.15); color: #7b96ff; }

/* Hilos de comentarios */
.thread { background: var(--bg-surface); border: 1px solid var(--border-base); border-radius: 10px; margin-bottom: 1rem; overflow: hidden; transition: border-color .15s; }
.thread.resolved { opacity: .6; }
.thread:hover { border-color: var(--border-strong); }
.thread-head { padding: .9rem 1.1rem; display: flex; gap: 1rem; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; background: var(--bg-subtle); border-bottom: 1px solid var(--border-base); }
.thread-meta { display:flex; flex-direction:column; gap:.2rem; }
.thread-meta .row1 { display: flex; gap: .6rem; align-items: center; flex-wrap: wrap; font-size: .82rem; }
.thread-meta .cliente-link { color: var(--text-muted); font-size: .72rem; }
.thread-meta .cliente-link a { color: var(--text-muted); }
.thread-status { display: flex; gap: .5rem; align-items: center; }
.status-pill { padding: .2rem .55rem; border-radius: 999px; font-size: .7rem; font-weight: 600; }
.status-pill.open { background: rgba(255, 200, 0, .14); color: #ffcc33; }
.status-pill.closed { background: rgba(var(--mint-rgb), .15); color: var(--mint); }
.thread-body { padding: .85rem 1.1rem; }
.comment { padding: .6rem 0; }
.comment + .comment { border-top: 1px dashed var(--border-base); margin-top: .6rem; }
.comment-author { font-weight: 600; font-size: .82rem; color: var(--text-primary); }
.comment-author.staff { color: var(--mint); }
.comment-author.staff::after { content: "Tres Puntos"; background: rgba(var(--mint-rgb), .15); color: var(--mint); padding: .1rem .4rem; border-radius: 999px; font-size: .62rem; margin-left: .4rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
.comment-meta { font-size: .72rem; color: var(--text-muted); margin-left: .5rem; }
.comment-text { white-space: pre-wrap; font-size: .9rem; color: var(--text-primary); margin-top: .25rem; line-height: 1.55; }
.reply { margin-left: 1.5rem; border-left: 2px solid rgba(var(--mint-rgb), .35); padding-left: .9rem; }
.reply-actions { margin-top: .4rem; display: flex; gap: .5rem; flex-wrap: wrap; }
.js-reply-edit-actions { margin-top: .4rem; display: flex; gap: .5rem; }
.btn-mini { background: transparent; border: 1px solid var(--border-base); color: var(--text-muted); padding: .22rem .6rem; border-radius: 4px; font-size: .72rem; cursor: pointer; font-family: inherit; font-weight: 500; }
.btn-mini:hover { color: var(--text-primary); border-color: var(--border-strong); }
.btn-mini.btn-publish { color: var(--mint); border-color: rgba(var(--mint-rgb), .45); }
.btn-mini.btn-publish:hover { background: rgba(var(--mint-rgb), .1); border-color: var(--mint); }
.btn-mini.btn-danger:hover { color: #ff6b6b; border-color: #ff6b6b; }
.js-reply-edit { background: var(--bg-base); color: var(--text-primary); border: 1px solid var(--border-strong); border-radius: 4px; padding: .5rem .65rem; font-family: inherit; font-size: .88rem; width: 100%; min-height: 80px; resize: vertical; margin-top: .35rem; }

/* Draft visual */
.comment.draft { border: 1px dashed rgba(var(--mint-rgb), .45); padding-left: .8rem; border-radius: 6px; background: rgba(var(--mint-rgb), .03); }
.comment.draft .comment-text { color: var(--text-secondary); }
.draft-pill { display: inline-block; background: rgba(var(--mint-rgb), .12); color: var(--mint); border: 1px solid rgba(var(--mint-rgb), .3); padding: .1rem .5rem; border-radius: 999px; font-size: .65rem; font-weight: 600; margin-left: .4rem; letter-spacing: .02em; }
.notified-pill { display: inline-block; background: var(--bg-muted); color: var(--text-muted); padding: .1rem .5rem; border-radius: 999px; font-size: .65rem; font-weight: 500; margin-left: .4rem; }

/* Barra "avisar cliente" */
.notify-bar { background: linear-gradient(135deg, rgba(var(--mint-rgb), .12), rgba(var(--mint-rgb), .04)); border: 1px solid rgba(var(--mint-rgb), .3); border-radius: 10px; padding: .85rem 1.1rem; display: flex; align-items: center; gap: 1rem; justify-content: space-between; flex-wrap: wrap; margin-bottom: 1.1rem; }
.notify-bar .text { color: var(--text-primary); font-size: .9rem; }
.notify-bar .text strong { color: var(--mint); }
.notify-bar .text small { color: var(--text-muted); display: block; font-size: .75rem; margin-top: .15rem; }
.notify-bar button { background: var(--mint); color: #000; border: none; padding: .55rem 1.1rem; border-radius: 6px; font-weight: 700; cursor: pointer; font-family: inherit; font-size: .85rem; display: inline-flex; align-items: center; gap: .5rem; }
.notify-bar button:hover { transform: translateY(-1px); }
.notify-bar.all-drafts { background: rgba(255, 200, 0, .08); border-color: rgba(255, 200, 0, .25); }
.notify-bar.all-drafts .text strong { color: #ffcc33; }

.thread-foot { padding: .75rem 1.1rem 1rem; border-top: 1px solid var(--border-base); background: var(--bg-base); }
.btn-reply-open { background: transparent; color: var(--mint); border: 1px dashed rgba(var(--mint-rgb), .45); padding: .5rem .85rem; border-radius: 6px; cursor: pointer; font-weight: 600; font-family: inherit; font-size: .8rem; width: 100%; transition: background .15s, border-style .15s; }
.btn-reply-open:hover { background: rgba(var(--mint-rgb), .08); border-style: solid; }
.reply-form { display: none; flex-direction: column; gap: .55rem; }
.reply-form.open { display: flex; }
.reply-form textarea { background: var(--bg-subtle); color: var(--text-primary); border: 1px solid var(--border-base); border-radius: 6px; padding: .6rem .75rem; font-family: inherit; font-size: .88rem; min-height: 90px; resize: vertical; }
.reply-form textarea:focus { outline: none; border-color: var(--mint); }
.reply-form .row { display: flex; gap: .5rem; justify-content: flex-end; align-items: center; }
.reply-form .hint { color: var(--text-muted); font-size: .72rem; margin-right: auto; }
.reply-form button { font-family: inherit; font-size: .82rem; padding: .5rem .95rem; border-radius: 6px; cursor: pointer; font-weight: 600; border: none; }
.btn-send { background: var(--mint); color: #000; }
.btn-send:disabled { opacity: .5; cursor: not-allowed; }
.btn-cancel { background: transparent; color: var(--text-muted); border: 1px solid var(--border-base); }
.btn-cancel:hover { color: var(--text-primary); border-color: var(--border-strong); }

.section-hint { color: var(--text-muted); font-size: .78rem; margin: .5rem 0 1rem; }

/* Filtro de hilos (todos / abiertos / cerrados) */
.thread-filter { display: inline-flex; background: var(--bg-surface); border: 1px solid var(--border-base); border-radius: 8px; padding: .2rem; gap: .15rem; margin-bottom: 1rem; }
.thread-filter button { background: transparent; border: 0; color: var(--text-muted); padding: .4rem .85rem; border-radius: 6px; cursor: pointer; font-family: inherit; font-size: .78rem; font-weight: 600; transition: all .15s; }
.thread-filter button:hover { color: var(--text-primary); }
.thread-filter button.active { background: var(--bg-muted); color: var(--mint); }
.thread-filter button .count { color: var(--text-muted); font-weight: 400; margin-left: .3rem; font-size: .72rem; }
.thread-filter button.active .count { color: var(--text-secondary); }
.thread-filter-empty { color: var(--text-muted); padding: 2rem; text-align: center; background: var(--bg-surface); border-radius: 8px; font-size: .85rem; display: none; }
.thread-filter-empty.visible { display: block; }
</style>
<script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
<?php
$adminSidebarActive = 'comentarios';
$adminSidebarPropuestaId = $filterPropuesta;
$adminSidebarPropuestaSlug = $filterPropuesta > 0 ? ($propuestas[array_search($filterPropuesta, array_column($propuestas, 'id'))]['slug'] ?? null) : null;
$adminSidebarPropuestas = $propuestas;
?>
<div class="admin-layout">
<?php include __DIR__ . '/master/admin-sidebar.php'; ?>

<main class="admin-main">
    <div class="admin-main-header">
        <h1 class="admin-main-title">
            <i data-lucide="message-square-text"></i>
            Comentarios y firmas
            <?php if ($filterPropuesta > 0 && $adminSidebarPropuestaSlug): ?>
                <small>· <?= e($propuestas[array_search($filterPropuesta, array_column($propuestas, 'id'))]['client_name'] ?? '') ?></small>
            <?php endif; ?>
        </h1>
        <div class="admin-main-actions">
            <span class="badge-global <?= $totalAbiertos === 0 ? 'zero' : '' ?>">
                <?= $totalAbiertos === 0 ? 'Sin pendientes' : $totalAbiertos . ' hilo' . ($totalAbiertos === 1 ? '' : 's') . ' pendiente' . ($totalAbiertos === 1 ? '' : 's') ?>
            </span>
        </div>
    </div>

    <section>
        <h2>Firmas de aprobación (<?= count($aprobaciones) ?>)</h2>
        <?php if (!$aprobaciones): ?>
            <div class="empty">Sin firmas todavía.</div>
        <?php else: ?>
        <table>
            <thead><tr>
                <th>Fecha</th><th>Cliente / Propuesta</th><th>Tipo</th><th>Firmante</th><th>Email</th><th>Versión</th><th>Hash</th><th>IP</th>
            </tr></thead>
            <tbody>
            <?php foreach ($aprobaciones as $a): ?>
                <tr>
                    <td><?=e(fecha($a['aprobado_at']))?></td>
                    <td><span class="cliente"><?=e($a['client_name'])?></span><br><small><?=e($a['slug'])?></small></td>
                    <td><span class="pill <?=$a['tipo']==='presupuesto'?'pdf':'doc'?>"><?=e($a['tipo'])?></span></td>
                    <td><?=e(($a['firmante_nombre'] ?? '') ?: '—')?> <?=e($a['firmante_apellidos'] ?? '')?></td>
                    <td><?=e(($a['firmante_email'] ?? '') ?: '—')?></td>
                    <td><?=e(($a['version_firmada'] ?? '') ?: '—')?></td>
                    <td class="hash" title="<?=e($a['firma_hash'] ?? '')?>"><?=e(substr($a['firma_hash'] ?? '', 0, 20))?><?=$a['firma_hash']?'…':'—'?></td>
                    <td><?=e(($a['ip_address'] ?? '') ?: '—')?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <section>
        <h2>Hilos de comentarios (<?= count($roots) ?>)</h2>
        <p class="section-hint">El cierre de un hilo lo hace el autor del comentario desde su vista. El admin responde y el cliente decide si queda resuelto.</p>

        <?php
        $countOpen = 0; $countClosed = 0;
        foreach ($roots as $r) { if ((int)$r['resuelto'] === 1) $countClosed++; else $countOpen++; }
        ?>
        <?php if (count($roots) > 0): ?>
            <div class="thread-filter" role="tablist">
                <button type="button" data-filter="open" class="active">Abiertos <span class="count"><?= $countOpen ?></span></button>
                <button type="button" data-filter="all">Todos <span class="count"><?= count($roots) ?></span></button>
                <button type="button" data-filter="closed">Cerrados <span class="count"><?= $countClosed ?></span></button>
            </div>
            <div class="thread-filter-empty" id="tp-filter-empty">No hay hilos en este estado.</div>
        <?php endif; ?>

        <?php
        // Contar respuestas staff publicadas pendientes de notificar al cliente, y borradores sin publicar
        $pendingNotify = 0; $draftsCount = 0;
        $clientEmail = ''; $clientProposalName = ''; $clientProposalSlug = ''; $proposalId = 0;
        foreach ($repliesByParent as $parentId => $replies) {
            foreach ($replies as $r) {
                if ((int)$r['is_staff'] === 1) {
                    if ((int)($r['is_draft'] ?? 0) === 1) $draftsCount++;
                    elseif (empty($r['notificado_at'])) $pendingNotify++;
                }
            }
        }
        if ($filterPropuesta > 0 && $roots) {
            $clientProposalName = $roots[0]['client_name'];
            $clientProposalSlug = $roots[0]['slug'];
            $proposalId = (int)$roots[0]['propuesta_id'];
            // Toma el email del primer autor con email (suele ser el cliente principal)
            foreach ($roots as $r) { if (!empty($r['autor_email'])) { $clientEmail = $r['autor_email']; break; } }
        }
        $viewUrl = ($filterPropuesta > 0 && $clientProposalSlug) ? (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'doc.trespuntos-lab.com') . '/p/' . $clientProposalSlug : '';
        ?>

        <?php
        // T5 · Gate: si todos los hilos resueltos y hay al menos uno → CTA para generar nueva versión
        $allResolved = count($roots) > 0 && $totalAbiertos === 0 && $draftsCount === 0;
        ?>
        <?php if ($filterPropuesta > 0 && $allResolved): ?>
            <div class="notify-bar" style="background: linear-gradient(135deg, rgba(var(--mint-rgb), .2), rgba(var(--mint-rgb), .06)); border-color: var(--mint); align-items: center;">
                <div class="text">
                    <strong style="display:inline-flex;align-items:center;gap:.4rem;"><i data-lucide="rocket" style="width:16px;height:16px;"></i> Todos los hilos resueltos · Lista para nueva versión</strong>
                    <small>Edita la propuesta y guarda una versión nueva, o avisa al cliente si ya está publicada.</small>
                </div>
                <div style="display:flex; gap:.55rem;">
                    <a href="admin.php?edit_id=<?= $proposalId ?>" style="background: transparent; color: var(--mint); border: 1px solid var(--mint); padding: .55rem 1.1rem; border-radius: 6px; font-weight: 600; cursor: pointer; font-family: inherit; font-size: .85rem; text-decoration: none; display: inline-flex; align-items: center; gap: .4rem;">
                        <i data-lucide="file-edit" style="width:14px;height:14px;"></i> Editar propuesta
                    </a>
                    <button type="button" onclick="announceNewVersion(<?= $proposalId ?>, <?= htmlspecialchars(json_encode($clientEmail), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($clientProposalName), ENT_QUOTES) ?>)" style="background: var(--mint); color: #000; border: none; padding: .55rem 1.1rem; border-radius: 6px; font-weight: 700; cursor: pointer; font-family: inherit; font-size: .85rem; display: inline-flex; align-items: center; gap: .4rem;">
                        <i data-lucide="megaphone" style="width:14px;height:14px;"></i> Avisar nueva versión
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($filterPropuesta > 0 && ($pendingNotify > 0 || $draftsCount > 0)): ?>
            <?php if ($draftsCount > 0 && $pendingNotify === 0): ?>
                <div class="notify-bar all-drafts">
                    <div class="text">
                        <strong><?= $draftsCount ?> borrador<?= $draftsCount === 1 ? '' : 'es' ?> sin publicar</strong>
                        <small>Revisa y publica antes de avisar al cliente.</small>
                    </div>
                </div>
            <?php else: ?>
                <div class="notify-bar">
                    <div class="text">
                        <strong><?= $pendingNotify ?> respuesta<?= $pendingNotify === 1 ? '' : 's' ?> publicada<?= $pendingNotify === 1 ? '' : 's' ?> sin notificar</strong>
                        <small>
                            <?= $draftsCount > 0 ? $draftsCount . ' borrador' . ($draftsCount === 1 ? '' : 'es') . ' aún sin publicar · ' : '' ?>
                            Se abrirá Gmail con el mensaje listo. Tú revisas y envías.
                        </small>
                    </div>
                    <button type="button" onclick="notifyClient(<?= $proposalId ?>, <?= htmlspecialchars(json_encode($clientEmail), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($clientProposalName), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($viewUrl), ENT_QUOTES) ?>)" style="display:inline-flex;align-items:center;gap:.4rem;">
                        <i data-lucide="mail" style="width:14px;height:14px;"></i> Avisar cliente
                    </button>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!$roots): ?>
            <div class="empty">Sin comentarios todavía.</div>
        <?php else: foreach ($roots as $root):
            $replies = $repliesByParent[$root['id']] ?? [];
            $isResolved = (int)$root['resuelto'] === 1;
        ?>
            <article class="thread <?= $isResolved ? 'resolved' : '' ?>" data-root-id="<?= (int)$root['id'] ?>">
                <header class="thread-head">
                    <div class="thread-meta">
                        <div class="row1">
                            <span class="section-tag"><?= e($root['section_title'] ?: $root['section_anchor']) ?></span>
                            <span class="cliente">· <?= e($root['client_name']) ?></span>
                            <span class="comment-meta"><?= e(fecha($root['created_at'])) ?></span>
                        </div>
                        <div class="cliente-link">
                            <a href="/p/<?= e($root['slug']) ?>#<?= e($root['section_anchor']) ?>" target="_blank">/p/<?= e($root['slug']) ?>#<?= e($root['section_anchor']) ?></a>
                        </div>
                    </div>
                    <div class="thread-status">
                        <?php if ($isResolved): ?>
                            <span class="status-pill closed"><i data-lucide="check-circle" style="width:12px;height:12px;vertical-align:-2px;"></i> Cerrado<?= $root['resuelto_por'] ? ' por ' . e($root['resuelto_por']) : '' ?></span>
                        <?php else: ?>
                            <span class="status-pill open"><i data-lucide="circle-dot" style="width:12px;height:12px;vertical-align:-2px;"></i> Abierto</span>
                        <?php endif; ?>
                    </div>
                </header>
                <div class="thread-body">
                    <div class="comment">
                        <span class="comment-author"><?= e(trim($root['autor_nombre'] . ' ' . $root['autor_apellidos'])) ?></span>
                        <span class="comment-meta"><?= e(fecha($root['created_at'])) ?></span>
                        <div class="comment-text"><?= e($root['texto']) ?></div>
                    </div>
                    <?php foreach ($replies as $r):
                        $isStaff = (int)$r['is_staff'] === 1;
                        $isDraft = (int)($r['is_draft'] ?? 0) === 1;
                        $notified = !empty($r['notificado_at']);
                    ?>
                        <div class="comment reply <?= $isDraft ? 'draft' : '' ?>" data-reply-id="<?= (int)$r['id'] ?>">
                            <span class="comment-author <?= $isStaff ? 'staff' : '' ?>">
                                <?= $isStaff ? 'Tres Puntos' : e(trim($r['autor_nombre'] . ' ' . $r['autor_apellidos'])) ?>
                            </span>
                            <span class="comment-meta"><?= e(fecha($r['created_at'])) ?></span>
                            <?php if ($isDraft): ?><span class="draft-pill"><i data-lucide="pencil" style="width:10px;height:10px;vertical-align:-1px;"></i> Borrador — el cliente no lo ve</span><?php endif; ?>
                            <?php if ($isStaff && !$isDraft && $notified): ?><span class="notified-pill" title="Avisado por email el <?= e(fecha($r['notificado_at'])) ?>"><i data-lucide="mail-check" style="width:10px;height:10px;vertical-align:-1px;"></i> Notificado</span><?php endif; ?>
                            <div class="comment-text js-reply-text"><?= e($r['texto']) ?></div>
                            <textarea class="js-reply-edit" style="display:none" maxlength="4000"><?= e($r['texto']) ?></textarea>
                            <?php if ($isStaff): ?>
                                <div class="reply-actions js-reply-actions">
                                <?php if ($isDraft): ?>
                                    <button type="button" class="btn-mini btn-publish" onclick="publishReply(<?= (int)$r['id'] ?>)">✓ Publicar</button>
                                    <button type="button" class="btn-mini" onclick="editReply(<?= (int)$r['id'] ?>)">Editar</button>
                                    <button type="button" class="btn-mini btn-danger" onclick="discardDraft(<?= (int)$r['id'] ?>)">Descartar</button>
                                <?php else: ?>
                                    <button type="button" class="btn-mini btn-danger" onclick="deleteReply(<?= (int)$r['id'] ?>)">Eliminar</button>
                                <?php endif; ?>
                                </div>
                                <div class="js-reply-edit-actions" style="display:none">
                                    <button type="button" class="btn-mini btn-publish" onclick="saveDraft(<?= (int)$r['id'] ?>, <?= $isDraft ? 'true' : 'false' ?>)">Guardar</button>
                                    <button type="button" class="btn-mini" onclick="cancelEdit(<?= (int)$r['id'] ?>)">Cancelar</button>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <footer class="thread-foot">
                    <button type="button" class="btn-reply-open" onclick="openReply(<?= (int)$root['id'] ?>)">
                        + Responder a <?= e($root['autor_nombre']) ?>
                    </button>
                    <form class="reply-form" id="form-<?= (int)$root['id'] ?>" onsubmit="sendReply(event, <?= (int)$root['id'] ?>)">
                        <textarea name="texto" placeholder="Escribe la respuesta…" required></textarea>
                        <div class="row">
                            <span class="hint">La verá el cliente en su drawer. Firmará como <strong>Tres Puntos</strong>.</span>
                            <button type="button" class="btn-cancel" onclick="closeReply(<?= (int)$root['id'] ?>)">Cancelar</button>
                            <button type="submit" class="btn-send">Enviar respuesta</button>
                        </div>
                    </form>
                </footer>
            </article>
        <?php endforeach; endif; ?>
    </section>
</main>
</div><!-- /.admin-layout -->

<script>
if (window.lucide) lucide.createIcons();
// Filtro de hilos — persiste en sessionStorage
(function() {
    const KEY = 'tp_admin_thread_filter';
    const filterBar = document.querySelector('.thread-filter');
    if (!filterBar) return;
    const emptyMsg = document.getElementById('tp-filter-empty');

    function apply(filter) {
        try { sessionStorage.setItem(KEY, filter); } catch(e) {}
        filterBar.querySelectorAll('button').forEach(b => b.classList.toggle('active', b.dataset.filter === filter));
        let visibleCount = 0;
        document.querySelectorAll('.thread').forEach(t => {
            const resolved = t.classList.contains('resolved');
            let show = true;
            if (filter === 'open')   show = !resolved;
            if (filter === 'closed') show = resolved;
            t.style.display = show ? '' : 'none';
            if (show) visibleCount++;
        });
        if (emptyMsg) emptyMsg.classList.toggle('visible', visibleCount === 0);
    }

    const saved = (() => { try { return sessionStorage.getItem(KEY); } catch(e) { return null; } })();
    apply(saved || 'open');  // default: abiertos

    filterBar.addEventListener('click', e => {
        const btn = e.target.closest('button[data-filter]');
        if (btn) apply(btn.dataset.filter);
    });
})();

function openReply(id) {
    document.getElementById('form-' + id).classList.add('open');
    document.querySelector('[data-root-id="' + id + '"] .btn-reply-open').style.display = 'none';
    document.querySelector('#form-' + id + ' textarea').focus();
}
function closeReply(id) {
    document.getElementById('form-' + id).classList.remove('open');
    document.querySelector('[data-root-id="' + id + '"] .btn-reply-open').style.display = '';
}
async function sendReply(e, parentId) {
    e.preventDefault();
    const form = e.target;
    const ta = form.querySelector('textarea');
    const btn = form.querySelector('.btn-send');
    const texto = ta.value.trim();
    if (!texto) return;
    btn.disabled = true; btn.textContent = 'Enviando…';
    const body = new URLSearchParams();
    body.append('action', 'add_staff_reply');
    body.append('parent_id', parentId);
    body.append('texto', texto);
    try {
        const r = await fetch('admin_feedback.php' + location.search, { method: 'POST', body }).then(r => r.json());
        if (!r.success) { alert(r.error || 'Error al enviar'); btn.disabled = false; btn.textContent = 'Enviar respuesta'; return; }
        location.reload();
    } catch (err) {
        alert('Error de red');
        btn.disabled = false; btn.textContent = 'Enviar respuesta';
    }
}
async function deleteReply(id) {
    if (!confirm('¿Eliminar esta respuesta?')) return;
    await postAction({ action: 'delete_reply', id }, 'No se pudo eliminar.');
}

async function publishReply(id) {
    if (!confirm('¿Publicar esta respuesta? El cliente la verá en cuanto entre al documento.')) return;
    // Si está en modo edición, toma el texto actual del textarea
    const wrap = document.querySelector('[data-reply-id="' + id + '"]');
    const ta = wrap && wrap.querySelector('.js-reply-edit');
    const editing = ta && ta.style.display !== 'none';
    const payload = { action: 'publish_reply', id };
    if (editing) payload.texto = ta.value.trim();
    await postAction(payload, 'No se pudo publicar.');
}

async function discardDraft(id) {
    if (!confirm('¿Descartar este borrador? No se podrá recuperar.')) return;
    await postAction({ action: 'discard_draft', id }, 'No se pudo descartar.');
}

function editReply(id) {
    const wrap = document.querySelector('[data-reply-id="' + id + '"]');
    if (!wrap) return;
    wrap.querySelector('.js-reply-text').style.display = 'none';
    wrap.querySelector('.js-reply-actions').style.display = 'none';
    wrap.querySelector('.js-reply-edit').style.display = 'block';
    wrap.querySelector('.js-reply-edit-actions').style.display = 'flex';
    wrap.querySelector('.js-reply-edit').focus();
}
function cancelEdit(id) {
    const wrap = document.querySelector('[data-reply-id="' + id + '"]');
    if (!wrap) return;
    wrap.querySelector('.js-reply-text').style.display = '';
    wrap.querySelector('.js-reply-actions').style.display = '';
    wrap.querySelector('.js-reply-edit').style.display = 'none';
    wrap.querySelector('.js-reply-edit-actions').style.display = 'none';
    // reset al texto original
    const original = wrap.querySelector('.js-reply-text').textContent;
    wrap.querySelector('.js-reply-edit').value = original;
}
async function saveDraft(id, isDraft) {
    const wrap = document.querySelector('[data-reply-id="' + id + '"]');
    const texto = wrap.querySelector('.js-reply-edit').value.trim();
    if (!texto) { alert('No puede quedar vacío.'); return; }
    const action = isDraft ? 'update_draft' : 'update_draft'; // siempre update_draft (solo permite editar drafts por seguridad)
    // Si no es draft, avisamos — solo dejamos editar drafts desde esta UI
    if (!isDraft) { alert('Solo se pueden editar los borradores desde aquí.'); return; }
    await postAction({ action, id, texto }, 'No se pudo guardar.');
}

async function postAction(payload, errMsg) {
    const body = new URLSearchParams();
    Object.entries(payload).forEach(([k, v]) => body.append(k, v));
    const r = await fetch('admin_feedback.php' + location.search, { method: 'POST', body }).then(r => r.json()).catch(() => ({}));
    if (r.success) location.reload(); else alert((r && r.error) || errMsg);
}

async function announceNewVersion(propuestaId, suggestedEmail, clientName) {
    let email = suggestedEmail;
    if (!email) {
        email = prompt('Email del destinatario:', '');
        if (!email) return;
    } else {
        const useIt = confirm('Avisar a ' + email + ' de la nueva versión?\n\n(Cancelar para usar otro email)');
        if (!useIt) {
            email = prompt('Email del destinatario:', suggestedEmail);
            if (!email) return;
        }
    }

    const defaultBullets = [
        'Integra todos los ajustes consensuados en la última ronda de comentarios',
        'Nueva librería de bloques administrables para crear landings autónomas',
        'Tracking de afiliación (Awin + Tradedoubler) integrado con CRMGO',
        'Automatización del Euríbor desde Banco de España',
        'Formulario de captación en 3 pasos con campos enriquecidos para scoring',
        'Stack WordPress a medida (Gutenberg + ACF, sin Elementor)',
        'Panel interno H2B retirado — CRM actual (CRMGO) mantiene la gestión',
        'Nueva sección I con dependencias pendientes para cerrar presupuesto'
    ].join('\n');

    const changes = prompt(
        'Bullets de cambios (uno por línea, editables):\n\n',
        defaultBullets
    );
    if (changes === null) return;

    const body = new URLSearchParams();
    body.append('action', 'send_version_announcement');
    body.append('propuesta_id', propuestaId);
    body.append('to', email);
    body.append('changes', changes);

    const r = await fetch('admin_feedback.php' + location.search, { method: 'POST', body }).then(r => r.json()).catch(() => ({}));
    if (r.success) {
        alert('✓ Aviso enviado a ' + email + ' (versión ' + r.version + ')');
    } else {
        alert('Error: ' + (r.error || 'desconocido'));
    }
}

async function notifyClient(propuestaId, email, clientName, viewUrl) {
    if (!email) {
        const manualEmail = prompt('No hay email del cliente registrado. Introduce el email:');
        if (!manualEmail) return;
        email = manualEmail;
    }
    if (!confirm('Se enviará el email a ' + email + ' desde jordan@trespuntos-lab.com (CC a jordi@trespuntoscomunicacion.es).\n\n¿Enviar ahora?')) return;

    const body = new URLSearchParams();
    body.append('action', 'send_notification');
    body.append('propuesta_id', propuestaId);
    body.append('to', email);

    const btn = event && event.target;
    if (btn) { btn.disabled = true; btn.textContent = 'Enviando…'; }

    try {
        const r = await fetch('admin_feedback.php' + location.search, { method: 'POST', body }).then(r => r.json());
        if (!r.success) {
            alert('Error al enviar: ' + (r.error || 'desconocido'));
            if (btn) { btn.disabled = false; btn.innerHTML = '<i data-lucide="mail" style="width:14px;height:14px;vertical-align:-2px;"></i> Avisar cliente'; if (window.lucide) lucide.createIcons(); }
            return;
        }
        alert('✓ Email enviado (' + r.sent + ' respuesta' + (r.sent === 1 ? '' : 's') + ' notificada' + (r.sent === 1 ? '' : 's') + ')');
        location.reload();
    } catch (err) {
        alert('Error de red: ' + err.message);
        if (btn) { btn.disabled = false; btn.innerHTML = '<i data-lucide="mail" style="width:14px;height:14px;vertical-align:-2px;"></i> Avisar cliente'; if (window.lucide) lucide.createIcons(); }
    }
}
</script>
</body></html>
