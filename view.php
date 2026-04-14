<?php
session_start();
require_once __DIR__ . '/config.php';
$base_path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
if ($base_path === '/' || $base_path === '\\') {
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
$error_pin = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pin'])) {
    if ($_POST['pin'] === $proposal['pin']) {
        $_SESSION[$session_key] = true;
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        header("Location: $protocol://" . $_SERVER['HTTP_HOST'] . $base_path . "/p/" . $slug);
        exit;
    }
    else {
        $error_pin = "PIN incorrecto.";
    }
}

$is_unlocked = isset($_SESSION[$session_key]) && $_SESSION[$session_key] === true;

if ($is_unlocked) {
    $view_timer_key = 'last_view_time_' . $proposal['id'];
    $user_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    // No contar visitas de la IP del administrador
    if ($user_ip !== '85.51.255.66' && (!isset($_SESSION[$view_timer_key]) || (time() - $_SESSION[$view_timer_key] > 300))) {
        $pdo->prepare("UPDATE propuestas SET views_count = views_count + 1, last_accessed_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$proposal['id']]);
        $_SESSION[$view_timer_key] = time();
    }

    $content = $proposal['html_content'];

    // AJAX Handler for approvals
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api_action'])) {
        header('Content-Type: application/json');
        $clientName = $proposal['client_name'] ?? '';

        if ($_POST['api_action'] === 'approve_doc') {
            $stmtObj = $pdo->prepare("SELECT COUNT(*) FROM aprobaciones WHERE propuesta_id = ? AND tipo = 'documento_funcional'");
            $stmtObj->execute([$proposal['id']]);
            if ($stmtObj->fetchColumn() == 0) {
                $pdo->prepare("INSERT INTO aprobaciones (propuesta_id, tipo, ip_address) VALUES (?, ?, ?)")
                    ->execute([$proposal['id'], 'documento_funcional', $_SERVER['REMOTE_ADDR']]);
            }
            sendTelegramNotification("✅ <b>Documento Aprobado</b>\nCliente: <b>" . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . "</b>");
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['api_action'] === 'approve_pdf') {
            $stmtObj = $pdo->prepare("SELECT COUNT(*) FROM aprobaciones WHERE propuesta_id = ? AND tipo = 'presupuesto'");
            $stmtObj->execute([$proposal['id']]);
            if ($stmtObj->fetchColumn() == 0) {
                $pdo->prepare("INSERT INTO aprobaciones (propuesta_id, tipo, ip_address) VALUES (?, ?, ?)")
                    ->execute([$proposal['id'], 'presupuesto', $_SERVER['REMOTE_ADDR']]);
            }
            sendTelegramNotification("✅💰 <b>Presupuesto Aprobado</b>\nCliente: <b>" . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . "</b>");
            echo json_encode(['success' => true]);
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
        $stmtObj = $pdo -> prepare("SELECT tipo FROM aprobaciones WHERE propuesta_id = ?");
        $stmtObj -> execute([$proposal['id']]);
        while ($row = $stmtObj -> fetch(PDO:: FETCH_ASSOC)) {
            if ($row['tipo'] === 'documento_funcional')
                $isDocApproved = true;
            if ($row['tipo'] === 'presupuesto')
                $isPdfApproved = true;
        }
        $hasPdf = !empty($proposal['presupuesto_pdf']);

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
        renderWrappedContent($proposal, $slug, $isDocApproved, $isPdfApproved, $hasPdf, $team, $base_path);
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

        renderPinGate($proposal, $error_pin, $base_path);
        exit;

        function renderPinGate($proposal, $error_pin, $base_path) {
?>
<!DOCTYPE html>
                <html lang="es" class="dark">

                    <head>
                        <meta charset="UTF-8">
                            <meta name="viewport" content="width=device-width, initial-scale=1.0">
                                <title>
                                    <?php echo htmlspecialchars($proposal['client_name']); ?> | PIN
                                </title>
                                <link
                                    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Plus+Jakarta+Sans:wght@700;800&display=swap"
                                    rel="stylesheet">
                                    <style>
                                        * {
                                            margin: 0;
                                        padding: 0;
                                        box-sizing: border-box;
        }

                                        body {
                                            font - family: 'Inter', sans-serif;
                                        background-color: #050505;
                                        color: #E0E0E0;
                                        min-height: 100vh;
                                        display: flex;
                                        align-items: center;
                                        justify-content: center;
        }

                                        .gate-container {
                                            width: 100%;
                                        max-width: 400px;
                                        padding: 2.5rem;
                                        text-align: center;
                                        background: #0A0A0A;
                                        border-radius: 24px;
                                        border: 1px solid #1A1A1A;
        }

                                        .gate-label {
                                            font - family: 'Plus Jakarta Sans', sans-serif;
                                        font-size: 10px;
                                        font-weight: 700;
                                        text-transform: uppercase;
                                        letter-spacing: 0.4em;
                                        color: rgba(255, 255, 255, 0.4);
                                        margin-bottom: 2rem;
        }

                                        .pin-input {
                                            width: 100%;
                                        background: transparent;
                                        border: none;
                                        border-bottom: 2px solid rgba(255, 255, 255, 0.1);
                                        text-align: center;
                                        font-size: 2.5rem;
                                        padding: 1rem 0;
                                        outline: none;
                                        color: #5DFFBF;
                                        font-family: monospace;
                                        letter-spacing: 0.3em;
                                        transition: all 0.3s ease;
        }

                                        .pin-input:focus {
                                            border - color: #5DFFBF;
        }

                                        .btn-unlock {
                                            width: 100%;
                                        background: #5DFFBF;
                                        color: #050505;
                                        font-family: 'Plus Jakarta Sans', sans-serif;
                                        font-weight: 800;
                                        padding: 1.1rem;
                                        border: none;
                                        border-radius: 12px;
                                        font-size: 13px;
                                        text-transform: uppercase;
                                        letter-spacing: 0.1em;
                                        cursor: pointer;
                                        margin-top: 2rem;
                                        transition: all 0.3s;
        }

                                        .pin-error {
                                            font - size: 11px;
                                        color: #ef4444;
                                        margin-top: 1rem;
        }
                                    </style>
                                </head>

                                <body>
                                    <div class="gate-container">
                                        <img src="/logo.svg" alt="Tres Puntos" style="height: 32px; margin-bottom: 2rem;">
                                            <h2 class="gate-label">Propuesta Confidencial</h2>
                                            <form method="POST">
                                                <input type="password" name="pin" maxlength="10" placeholder="••••" required autofocus class="pin-input">
                                                    <?php if ($error_pin): ?>
                                                    <p class="pin-error">
                                                        <?php echo $error_pin; ?>
                                                    </p>
                                                    <?php
    endif; ?>
                                                    <button type="submit" class="btn-unlock">Continuar</button>
                                            </form>
                                    </div>
                                </body>

                            </html>
                            <?php
}

                            function renderWrappedContent($proposal, $slug, $isDocApproved = false, $isPdfApproved = false, $hasPdf = false, $team = [], $base_path = '')
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
            --bg-base: #0E0E0E;
            --bg-surface: #141414;
            --bg-nav-hover: #1A1A1A;
            --bg-nav-active: #2A2A2A;
            --text-primary: #F5F5F5;
            --text-secondary: #B3B3B3;
            --text-muted: #8A8A8A;
            --border-base: #1F1F1F;
            --border-strong: #2A2A2A;
            --font-heading: 'Plus Jakarta Sans', sans-serif;
            --overlay-scrim: rgba(0, 0, 0, 0.75);
        }

        [data-theme="light"] {
            --tp-primary: #0FA36C;
            --tp-primary-rgb: 15, 163, 108;
            --bg-base: #F7F6F3;
            --bg-surface: #FFFFFF;
            --bg-nav-hover: #F0EFEB;
            --bg-nav-active: #E8E6E0;
            --text-primary: #141414;
            --text-secondary: #4A4A4A;
            --text-muted: #6E6E6E;
            --border-base: #E4E2DC;
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
            margin-bottom: 5rem;
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
    </style>
</head>

<body>
    <div class="mobile-header">
        <img src="/logo.svg" alt="Tres Puntos" class="mobile-logo">
        <button class="menu-toggle" onclick="toggleMobileMenu()"><i data-lucide="menu"></i></button>
    </div>

    <div class="mobile-nav-overlay" id="mobileNav">
        <div class="mobile-nav-header">
            <img src="/logo.svg" alt="Tres Puntos" style="height: 24px;">
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
            <div class="sidebar-brand"><img src="/logo.svg" alt="Tres Puntos" style="height: 38px;"></div>
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
                <div id="content-area">
                    <?php echo $proposal['html_content']; ?>
                </div>

                <div id="content-areas-extensions">
                    <?php include __DIR__ . '/metodologia.php'; ?>
                    <div id="equipo-extension-area" style="margin-top: 4rem;"></div>
                    <div class="cta-block" id="sec-avanzamos-doc">
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

                    <?php if ($hasPdf): ?>
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
                <div class="modal-actions">
                    <button class="btn-modal-secondary" onclick="closeModal('approve')">Cancelar</button>
                    <button class="btn-modal-primary" onclick="submitApproval()"><i data-lucide="send"
                            style="width:16px;height:16px;"></i> Confirmar Aprobación</button>
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
                <div class="modal-actions">
                    <button class="btn-modal-secondary" onclick="closeModal('approve-pdf')">Cancelar</button>
                    <button class="btn-modal-primary" onclick="submitPdfApproval()"><i data-lucide="send"
                            style="width:16px;height:16px;"></i> Confirmar Presupuesto</button>
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
                if (tag === 'h2' && (low === 'documento funcional' || low === 'documentación funcional' || low.includes('proyecto web'))) {
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
        });

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

        async function submitApproval() {
            const btn = document.querySelector('#approve-form .btn-modal-primary');
            btn.disabled = true; btn.textContent = 'Enviando...';
            await apiCall('approve_doc');
            setTimeout(() => window.location.reload(), 1000);
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
            const btn = document.querySelector('#approve-pdf-form .btn-modal-primary');
            btn.disabled = true; btn.textContent = 'Enviando...';
            await apiCall('approve_pdf');
            setTimeout(() => window.location.reload(), 1000);
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
</body>

</html>
<?php
}
?>