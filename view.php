<?php
session_start();
require_once __DIR__ . '/config.php';
$base_path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
if ($base_path === '/' || $base_path === '\\') {
    $base_path = '';
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
    if (!isset($_SESSION[$view_timer_key]) || (time() - $_SESSION[$view_timer_key] > 300)) {
        $pdo->prepare("UPDATE propuestas SET views_count = views_count + 1, last_accessed_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$proposal['id']]);
        $_SESSION[$view_timer_key] = time();
    }

    $content = $proposal['html_content'];

    // AJAX Handler for approvals
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api_action'])) {
        header('Content-Type: application/json');
        if ($_POST['api_action'] === 'approve_doc') {
            $stmtObj = $pdo->prepare("SELECT COUNT(*) FROM aprobaciones WHERE propuesta_id = ? AND tipo = 'documento_funcional'");
            $stmtObj->execute([$proposal['id']]);
            if ($stmtObj->fetchColumn() == 0) {
                $pdo->prepare("INSERT INTO aprobaciones (propuesta_id, tipo, ip_address) VALUES (?, ?, ?)")
                    ->execute([$proposal['id'], 'documento_funcional', $_SERVER['REMOTE_ADDR']]);
            }
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
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['api_action'] === 'reject_pdf') {
            $pdo->prepare("INSERT INTO feedback_presupuesto (propuesta_id, tipo_accion, comentario) VALUES (?, ?, ?)")
                ->execute([$proposal['id'], 'presupuesto_rechazado_o_cambios', $_POST['comment'] ?? '']);
            echo json_encode(['success' => true]);
            exit;
        }
        if ($_POST['api_action'] === 'comment_doc') {
            $pdo->prepare("INSERT INTO feedback_presupuesto (propuesta_id, tipo_accion, comentario) VALUES (?, ?, ?)")
                ->execute([$proposal['id'], 'comentario_documento', $_POST['comment'] ?? '']);
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
< !DOCTYPE html >
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
    < !DOCTYPE html >
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
                                                <input type="password" name="pin" maxlength="10" placeholder="••••" required autofocus
                                                    class="pin-input">
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
        < !DOCTYPE html>
                            <html lang="es" class="scroll-smooth">

                                <head>
                                    <meta charset="utf-8">
                                        <meta name="viewport" content="width=device-width, initial-scale=1.0">
                                            <title>
                                                <?php echo htmlspecialchars($proposal['client_name']); ?> | Propuesta Tres Puntos
                                            </title>
                                            <link
                                                href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Plus+Jakarta+Sans:wght@700;800&display=swap"
                                                rel="stylesheet">
                                                <script src="https://unpkg.com/lucide@latest"></script>
                <style>
                    :root {
                        --tp-primary: #5DFFBF;
                        --bg-base: #0E0E0E;
                        --bg-surface: #141414;
                        --text-primary: #B0B0B0;
                        --text-secondary: #BDBDBD;
                        --border-base: #1F1F1F;
                        --font-heading: 'Plus Jakarta Sans', sans-serif;
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
                        background: #1A1A1A;
                        color: #FFF;
                    }

                    .nav-link.active {
                        background: #2A2A2A;
                        color: #FFF;
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
                        background: rgba(93, 255, 191, 0.08);
                        margin-top: 1rem;
                        margin-bottom: 1rem;
                        text-align: center;
                        border: 1px solid rgba(93, 255, 191, 0.2);
                    }

                    .nav-link-cta:hover {
                        background: rgba(93, 255, 191, 0.15);
                        border-color: rgba(93, 255, 191, 0.4);
                    }

                    .nav-link-cta.active {
                        background: rgba(93, 255, 191, 0.2);
                        border-color: var(--tp-primary);
                        font-weight: 700;
                        box-shadow: 0 0 15px rgba(93, 255, 191, 0.1);
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
                        background: #141414;
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
                        color: #FFF;
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
                        color: #FFF;
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
                        background: #0E0E0E;
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
                        color: #FFF;
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
                        color: #FFF;
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
                        color: #FFF;
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
                        background: #1A1A1A;
                        color: #FFF;
                        border-color: #444;
                    }

                    main {
                        margin-left: 320px;
                        flex: 1;
                        padding: 6rem 4rem;
                    }

                    .content-wrapper {
                        max-width: 840px;
                        margin: 0 auto;
                    }

                    h1 {
                        font-family: var(--font-heading);
                        font-size: 3.5rem;
                        font-weight: 800;
                        color: #FFF;
                        letter-spacing: -0.03em;
                        margin-bottom: 1rem;
                    }

                    .doc-meta {
                        font-size: 1rem;
                        color: #666;
                        margin-bottom: 5rem;
                    }

                    .content-wrapper h2 {
                        font-family: var(--font-heading);
                        font-size: 2.2rem;
                        margin-top: 5rem;
                        margin-bottom: 2rem;
                        color: #FFF;
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
                        color: #EEE;
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
                        left: 320px;
                        right: 0;
                        height: 3px;
                        background: rgba(0, 0, 0, 0.5);
                        z-index: 1000;
                    }

                    .progress-fill {
                        height: 100%;
                        background: var(--tp-primary);
                        width: 0%;
                        transition: width 0.1s;
                    }

                    /* Content Cards (tp-grid & tp-card) */
                    .tp-grid {
                        display: grid;
                        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
                        gap: 1rem;
                        margin: 2rem 0;
                        width: 100%;
                    }

                    .tp-card {
                        background: rgba(255, 255, 255, 0.03);
                        border: 1px solid rgba(255, 255, 255, 0.08);
                        border-radius: 14px;
                        padding: 1.25rem;
                        position: relative;
                        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                        overflow: hidden;
                        display: flex;
                        flex-direction: column;
                    }

                    .tp-card::before {
                        content: '';
                        position: absolute;
                        top: 0;
                        left: 0;
                        right: 0;
                        height: 2px;
                        background: linear-gradient(90deg, transparent, var(--tp-primary), transparent);
                        opacity: 0;
                        transition: opacity 0.3s ease;
                    }

                    .tp-card:hover {
                        transform: translateY(-3px);
                        background: rgba(255, 255, 255, 0.05);
                        border-color: rgba(93, 255, 191, 0.25);
                        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.4), 0 0 15px rgba(93, 255, 191, 0.04);
                    }

                    .tp-card:hover::before {
                        opacity: 1;
                    }

                    .tp-card-icon {
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                        width: 40px;
                        height: 40px;
                        border-radius: 10px;
                        background: rgba(93, 255, 191, 0.1);
                        color: var(--tp-primary);
                        margin-bottom: 1rem;
                    }

                    .tp-card-icon:empty {
                        display: none;
                    }

                    .tp-card-icon svg:not([data-lucide])~.tp-card-icon {
                        display: none;
                    }

                    .tp-card-icon svg {
                        width: 20px;
                        height: 20px;
                    }

                    .tp-card-number {
                        position: absolute;
                        top: 1rem;
                        right: 1rem;
                        font-family: var(--font-heading);
                        font-size: 2.2rem;
                        font-weight: 800;
                        line-height: 1;
                        color: rgba(255, 255, 255, 0.04);
                        pointer-events: none;
                    }

                    .tp-card h3 {
                        font-family: var(--font-heading);
                        font-size: 1.05rem !important;
                        color: #E0E0E0 !important;
                        margin: 0 0 0.5rem 0 !important;
                    }

                    .tp-card p {
                        margin-bottom: 0 !important;
                        font-size: 0.92rem !important;
                        color: #C8C8C8 !important;
                        line-height: 1.5 !important;
                        font-weight: 400 !important;
                    }

                    .tp-card h3+p {
                        font-size: 0.88rem !important;
                        color: #999 !important;
                        font-weight: 400 !important;
                    }

                    /* Team Cards */
                    .team-grid {
                        display: grid;
                        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
                        gap: 1.5rem;
                        margin: 3rem 0;
                        width: 100%;
                    }

                    .team-card {
                        background: #141414;
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
                        background: #0A0A0A;
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
                        color: #FFF;
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
                        color: #FFF;
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
                        margin-bottom: 0.5rem;
                        border-bottom: 1px solid rgba(255, 255, 255, 0.03);
                    }

                    .mobile-nav-link {
                        display: flex;
                        align-items: center;
                        padding: 1.25rem 0.5rem;
                        color: #FFF;
                        text-decoration: none;
                        font-size: 1.1rem;
                        font-weight: 600;
                        font-family: var(--font-heading);
                    }

                    @media (max-width: 1024px) {
                        aside {
                            width: 280px;
                        }

                        main {
                            margin-left: 280px;
                            padding: 4rem 2rem;
                        }

                        .progress-bar {
                            left: 280px;
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
                            left: 0;
                            top: 64px;
                        }

                        h1 {
                            font-size: 2.2rem;
                        }

                        .mobile-header {
                            display: flex;
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
                <div class="app-container">
                    <aside>
                        <div class="sidebar-brand"><img src="/logo.svg" alt="Tres Puntos" style="height: 38px;"></div>
                        <div class="sidebar-nav-container">
                            <ul id="sidebar-nav"></ul>
                        </div>
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
                            <style>
                                /* Card Styles - Inline Failsafe */
                                .tp-grid {
                                    display: grid;
                                    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
                                    gap: 1rem;
                                    margin: 2rem 0;
                                    width: 100%;
                                }

                                .tp-card {
                                    background: rgba(255, 255, 255, 0.03);
                                    border: 1px solid rgba(255, 255, 255, 0.08);
                                    border-radius: 14px;
                                    padding: 1.25rem;
                                    position: relative;
                                    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                                    overflow: hidden;
                                    display: flex;
                                    flex-direction: column;
                                }

                                .tp-card::before {
                                    content: '';
                                    position: absolute;
                                    top: 0;
                                    left: 0;
                                    right: 0;
                                    height: 2px;
                                    background: linear-gradient(90deg, transparent, var(--tp-primary, #5DFFBF), transparent);
                                    opacity: 0;
                                    transition: opacity 0.3s ease;
                                }

                                .tp-card:hover {
                                    transform: translateY(-3px);
                                    background: rgba(255, 255, 255, 0.05);
                                    border-color: rgba(93, 255, 191, 0.25);
                                    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.4), 0 0 15px rgba(93, 255, 191, 0.04);
                                }

                                .tp-card:hover::before {
                                    opacity: 1;
                                }

                                .tp-card-icon {
                                    display: inline-flex;
                                    align-items: center;
                                    justify-content: center;
                                    width: 40px;
                                    height: 40px;
                                    border-radius: 10px;
                                    background: rgba(93, 255, 191, 0.1);
                                    color: var(--tp-primary, #5DFFBF);
                                    margin-bottom: 1rem;
                                }

                                .tp-card-icon:empty {
                                    display: none;
                                }

                                .tp-card-icon svg {
                                    width: 20px;
                                    height: 20px;
                                }

                                .tp-card-number {
                                    position: absolute;
                                    top: 1rem;
                                    right: 1rem;
                                    font-family: 'Plus Jakarta Sans', sans-serif;
                                    font-size: 2.2rem;
                                    font-weight: 800;
                                    line-height: 1;
                                    color: rgba(255, 255, 255, 0.04);
                                    pointer-events: none;
                                }

                                .tp-card h3 {
                                    font-family: 'Plus Jakarta Sans', sans-serif;
                                    font-size: 1.05rem !important;
                                    color: #E0E0E0 !important;
                                    margin: 0 0 0.5rem 0 !important;
                                }

                                .tp-card p {
                                    margin-bottom: 0 !important;
                                    font-size: 0.92rem !important;
                                    color: #C8C8C8 !important;
                                    line-height: 1.5 !important;
                                    font-weight: 400 !important;
                                }

                                .tp-card h3+p {
                                    font-size: 0.88rem !important;
                                    color: #999 !important;
                                    font-weight: 400 !important;
                                }
                            </style>
                            <div id="content-area">
                                <?php echo $proposal['html_content']; ?>
                            </div>

                            <div id="content-areas-extensions">
                                <div class="cta-block" id="sec-avanzamos-doc">
                                    <?php if (!$isDocApproved): ?>
                                    <h2
                                        style="font-family: var(--font-heading); font-size: 2.5rem; color: #FFF; margin-bottom: 1rem; margin-top: 0; display: block;">
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
                                        style="font-family: var(--font-heading); font-size: 2.5rem; color: #FFF; margin-bottom: 1rem; margin-top: 0; display: block;">
                                        Presupuesto de Proyecto</h2>
                                    <div
                                        style="background: var(--bg-surface); padding: 1rem; border-radius: 16px; border: 1px solid var(--border-base); margin-bottom: 3rem; height: 800px;">
                                        <iframe
                                            src="/uploads/presupuestos/<?php echo htmlspecialchars($proposal['presupuesto_pdf']); ?>"
                                            width="100%" height="100%"
                                            style="border:none; border-radius: 8px;"></iframe>
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
                            <div class="modal-icon green"><i data-lucide="check-circle"
                                    style="width:28px;height:28px;"></i></div>
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
                                    data-lucide="check-square"
                                    style="width:48px;height:48px;color:var(--tp-primary);"></i></div>
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
                            <div class="modal-icon blue"><i data-lucide="message-square"
                                    style="width:28px;height:28px;"></i></div>
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
                            <div class="modal-icon green"><i data-lucide="check-circle"
                                    style="width:28px;height:28px;"></i></div>
                            <h3>Aprobar Presupuesto</h3>
                            <p>Al confirmar, daremos por cerrado el presupuesto y te explicaremos los siguientes pasos
                                de
                                facturación y kickoff del proyecto.</p>
                            <div class="modal-actions">
                                <button class="btn-modal-secondary"
                                    onclick="closeModal('approve-pdf')">Cancelar</button>
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
                                            teamHeader.after(grid);
            } else if (mountPoint) {
                const internalElements = Array.from(mountPoint.children);
                internalElements.forEach(el => {
                    if (el.tagName !== 'H2' && el.tagName !== 'H3') el.remove();
                });
                                            mountPoint.appendChild(grid);
            } else {
                                                area.appendChild(grid);
            }
        }

                                            function setupNavigation() {
            const nav = document.getElementById('sidebar-nav');
                                            const area = document.getElementById('content-area');
                                            const extArea = document.getElementById('content-areas-extensions');
                                            if (!nav) return [];
                                            let allHeaders = [];
                                            if (area) allHeaders = allHeaders.concat(Array.from(area.querySelectorAll('h2')));
                                            if (extArea) allHeaders = allHeaders.concat(Array.from(extArea.querySelectorAll('h2')));

                                            const tracked = [];
                                            nav.innerHTML = '<li class="nav-item"><a href="#" class="nav-link active" id="nav-intro"><span>Inicio</span></a></li>';

                                            const mobileContainer = document.getElementById('mobile-nav-container');
                                            if (mobileContainer) mobileContainer.innerHTML = '<li class="mobile-nav-item"><a href="#" class="mobile-nav-link" onclick="toggleMobileMenu()"><span>Inicio</span></a></li>';

            allHeaders.forEach((el, i) => {
                                                let text = el.innerText ? el.innerText.trim() : '';
                                            const navLabel = text.replace(/^[\d\.]+\s*/, '');
                                            const low = text.toLowerCase();
                                            const tag = el.tagName ? el.tagName.toLowerCase() : '';

                                            if (i < 8 && (low === "documento funcional" || low === "documentación funcional" || low === "documento funcional aprobado" || low === "presupuesto aprobado" || low.includes("proyecto web") || low.includes("nextica law"))) {
                    if (low === "documento funcional" || low === "documentación funcional" || low.includes("proyecto web")) {
                                                el.style.display = 'none';
                                            return;
                    }
                }
                                            if (tag !== 'h2' || text.length < 3 || el.style.display === 'none') return;
                                            if (!el.id) el.id = 'sec-' + i;
                                            tracked.push(el);

                                            const li = document.createElement('li');
                                            li.className = 'nav-item';
                                            const isCTA = low.includes("avanzamos");
                                            const className = isCTA ? 'nav-link-cta active' : 'nav-link';
                                            li.innerHTML = `<a href="#${el.id}" class="${className}"><span>${navLabel}</span></a>`;
                                            nav.appendChild(li);

                                            if (mobileContainer) {
                    const mLi = document.createElement('li');
                                            mLi.className = 'mobile-nav-item';
                                            mLi.innerHTML = `<a href="#${el.id}" class="mobile-nav-link" onclick="toggleMobileMenu()"><span>${navLabel}</span></a>`;
                                            mobileContainer.appendChild(mLi);
                }
            });
                                            return tracked;
        }

                                            function toggleMobileMenu() {
            const menu = document.getElementById('mobileNav');
                                            const isOpen = menu.classList.contains('open');
                                            if (isOpen) {menu.classList.remove('open'); document.body.style.overflow = ''; }
                                            else {menu.classList.add('open'); document.body.style.overflow = 'hidden'; }
        }

                                            let sections = [];
        window.addEventListener('DOMContentLoaded', () => {
                                                injectTeamSection();
                                            sections = setupNavigation();
                                            if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
        });

                                            let isScrolling = false;
        window.addEventListener('scroll', () => {
            if (!isScrolling) {
                                                window.requestAnimationFrame(() => {
                                                    const scrollPos = window.scrollY + (window.innerHeight / 3);
                                                    let current = "";
                                                    if (window.scrollY < 50) current = "";
                                                    else {
                                                        sections.forEach(el => {
                                                            const offsetTop = el.getBoundingClientRect().top + window.scrollY;
                                                            if (offsetTop <= scrollPos) current = el.id;
                                                        });
                                                    }
                                                    document.querySelectorAll(".nav-link").forEach(l => {
                                                        l.classList.remove("active");
                                                        const href = l.getAttribute("href");
                                                        if (current && href === "#" + current) l.classList.add("active");
                                                        else if (!current && l.id === 'nav-intro') l.classList.add("active");
                                                    });
                                                    const winScroll = window.scrollY;
                                                    const height = document.documentElement.scrollHeight - window.innerHeight;
                                                    if (height > 0) {
                                                        const scrolled = (winScroll / height) * 100;
                                                        const fill = document.getElementById("progressFill");
                                                        if (fill) fill.style.width = scrolled + "%";
                                                    }
                                                    isScrolling = false;
                                                });
                                            isScrolling = true;
            }
        });

                                            const BOT_TOKEN = '8201699988:AAHZ6UeItc1I6EkULkrV6mozxkLO80t7j58';
                                            const CHAT_ID = '7313439878';
                                            const DOC_NAME = '<?php echo addslashes($proposal["client_name"]); ?>';

                                            function openModal(type) {document.getElementById('modal-' + type).classList.add('open'); }
                                            function closeModal(type) {document.getElementById('modal-' + type).classList.remove('open'); }
        document.querySelectorAll('.modal-overlay').forEach(m => {
                                                m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
        });

                                            function sendTelegram(text) {
            return fetch(`https://api.telegram.org/bot${BOT_TOKEN}/sendMessage`, {
                                                method: 'POST',
                                            headers: {'Content-Type': 'application/json' },
                                            body: JSON.stringify({chat_id: CHAT_ID, text: text, parse_mode: 'HTML' })
            }).catch(e => console.error('Telegram error:', e));
        }

                                            async function apiCall(action, params = { }) {
            const formData = new URLSearchParams();
                                            formData.append('api_action', action);
                                            for (let k in params) formData.append(k, params[k]);
                                            return fetch(window.location.href, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded' }, body: formData });
        }

                                            async function submitApproval() {
            const btn = document.querySelector('#approve-form .btn-modal-primary');
                                            btn.disabled = true; btn.textContent = 'Enviando...';
                                            await apiCall('approve_doc');
                                            try {await sendTelegram(`✅ <b>Documento Aprobado</b>\nCliente: <b>${DOC_NAME}</b>`); } catch(e){ }
            setTimeout(() => window.location.reload(), 1000);
        }

                                            async function submitComment() {
            const text = document.getElementById('comment-text').value.trim();
                                            if (!text) {document.getElementById('comment-text').focus(); return; }
                                            const btn = document.querySelector('#comment-form .btn-modal-primary');
                                            btn.disabled = true; btn.textContent = 'Enviando...';
                                            await apiCall('comment_doc', {comment: text });
                                            const safeText = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                                        try {await sendTelegram(`💬 <b>Comentarios del cliente</b>\nCliente: <b>${DOC_NAME}</b>\n\n${safeText}`); } catch(e){ }
                                        document.getElementById('comment-form').style.display = 'none';
                                        document.getElementById('comment-success').style.display = 'block';
        }

                                        async function submitPdfApproval() {
            const btn = document.querySelector('#approve-pdf-form .btn-modal-primary');
                                        btn.disabled = true; btn.textContent = 'Enviando...';
                                        await apiCall('approve_pdf');
                                        try {await sendTelegram(`✅💰 <b>Presupuesto Aprobado</b>\nCliente: <b>${DOC_NAME}</b>`); } catch(e){ }
            setTimeout(() => window.location.reload(), 1000); 
        }

                                        async function submitPdfRejection() {
            const text = document.getElementById('reject-pdf-text').value.trim();
                                        if (!text) {document.getElementById('reject-pdf-text').focus(); return; }
                                        const btn = document.querySelector('#reject-pdf-form .btn-modal-primary');
                                        btn.disabled = true; btn.textContent = 'Enviando...';
                                        await apiCall('reject_pdf', {comment: text });
                                        const safeText = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                                    try {await sendTelegram(`❌ <b>Cambios en Presupuesto</b>\nCliente: <b>${DOC_NAME}</b>\n\n${safeText}`); } catch(e){ }
                                    document.getElementById('reject-pdf-form').style.display = 'none';
                                    document.getElementById('reject-pdf-success').style.display = 'block';
        }
                </script>

                <!-- Calendly Widget -->
                <link href="https://assets.calendly.com/assets/external/widget.css" rel="stylesheet">
                <script src="https://assets.calendly.com/assets/external/widget.js" type="text/javascript"
                    async></script>
            </body>

            </html>
            <?php
}
?>