<?php
session_start();
require_once __DIR__ . '/config.php';
$base_path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');

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
            // Maintain the same visual order logic (first by user-defined order, then creation date).
            $stmtTeam = $pdo -> prepare("SELECT * FROM equipo WHERE id IN ($placeholders) ORDER BY orden ASC, created_at DESC");
            $stmtTeam -> execute($equipo_ids);
            $team = $stmtTeam -> fetchAll(PDO:: FETCH_ASSOC);
        }
        renderWrappedContent($proposal, $slug, $isDocApproved, $isPdfApproved, $hasPdf, $team, $base_path);
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
                                    <script src="https://unpkg.com/lucide@latest"></script>
</head>

<body>
    <div class="gate-container">
        <img src="<?php echo htmlspecialchars($base_path); ?>/logo-trespuntos.svg" alt="Tres Puntos"
            style="height: 32px; margin-bottom: 2rem;">
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

        .content-wrapper strong {
            color: var(--tp-primary);
            font-weight: 600;
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

        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            background: var(--tp-primary);
            color: #000;
            font-weight: 700;
            border-radius: 8px;
            text-decoration: none;
            margin-top: 2rem;
            transition: transform 0.2s;
        }

        .btn-action:hover {
            transform: translateY(-2px);
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

        .mobile-nav-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 3rem;
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
        <img src="<?php echo htmlspecialchars($base_path); ?>/logo-trespuntos.svg" alt="Tres Puntos"
            class="mobile-logo">
        <button class="menu-toggle" onclick="toggleMobileMenu()"><i data-lucide="menu"></i></button>
    </div>

    <div class="mobile-nav-overlay" id="mobileNav">
        <div class="mobile-nav-header">
            <img src="<?php echo htmlspecialchars($base_path); ?>/logo-trespuntos.svg" alt="Tres Puntos"
                style="height: 24px;">
            <button class="menu-toggle" onclick="toggleMobileMenu()"><i data-lucide="x"></i></button>
        </div>
        <ul class="mobile-nav-list" id="mobile-nav-container"></ul>
    </div>
    <div class="progress-bar">
        <div class="progress-fill" id="progressFill"></div>
    </div>
    <div class="app-container">
        <aside>
            <div class="sidebar-brand"><img src="<?php echo htmlspecialchars($base_path); ?>/logo-trespuntos.svg"
                    alt="Tres Puntos" style="height: 38px;"></div>
            <div class="sidebar-nav-container">
                <ul id="sidebar-nav"></ul>
            </div>
        </aside>
        <main>
            <div class="content-wrapper">
                <?php
    if (!empty($proposal['created_at'])) {
        $createdAt = strtotime($proposal['created_at']);
        $meses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
        $formattedDate = date('j', $createdAt) . ' de ' . $meses[date('n', $createdAt) - 1] . ' de ' . date('Y', $createdAt);
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
                    <!-- Base Document Block CTA -->
                    <div class="cta-block" id="sec-avanzamos-doc">
                        <?php if (!$isDocApproved): ?>
                        <h2
                            style="font-family: var(--font-heading); font-size: 2.5rem; color: #FFF; margin-bottom: 1rem; margin-top: 0; display: block;">
                            ¿Avanzamos con el proyecto?</h2>
                        <p>Si este documento refleja correctamente el alcance y los objetivos, podemos validarlo y
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
                        <p style="margin-bottom: 2rem;"><strong>En espera:</strong> Ya estamos trabajando minuciosamente
                            en tu presupuesto. Lo subiremos a esta misma página en breve y te notificaremos.</p>
                        <?php
        else: ?>
                        <p style="margin-bottom: 2rem;">Has aprobado satisfactoriamente los detalles de este documento
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
                    <!-- PDF View Block CTA -->
                    <div class="cta-block" id="sec-presupuesto" style="margin-top: 4rem;">
                        <h2
                            style="font-family: var(--font-heading); font-size: 2.5rem; color: #FFF; margin-bottom: 1rem; margin-top: 0; display: block;">
                            Presupuesto de Proyecto</h2>
                        <div
                            style="background: var(--bg-surface); padding: 1rem; border-radius: 16px; border: 1px solid var(--border-base); margin-bottom: 3rem; height: 800px;">
                            <iframe
                                src="<?php echo htmlspecialchars($base_path); ?>/uploads/presupuestos/<?php echo htmlspecialchars($proposal['presupuesto_pdf']); ?>"
                                width="100%" height="100%" style="border:none; border-radius: 8px;"></iframe>
                        </div>

                        <?php if (!$isPdfApproved): ?>
                        <p>Revisa el presupuesto detallado en el visor superior. Si todo es correcto, podemos proceder a
                            la aprobación formal.</p>
                        <div class="cta-buttons">
                            <button class="btn-cta-primary" onclick="openModal('approve-pdf')"><i
                                    data-lucide="check-circle"></i> Aprobar Presupuesto</button>
                            <button class="btn-cta-secondary" onclick="openModal('reject-pdf')"><i
                                    data-lucide="x-circle"></i> Denegar / Cambios</button>
                            <a href="<?php echo htmlspecialchars($base_path); ?>/uploads/presupuestos/<?php echo htmlspecialchars($proposal['presupuesto_pdf']); ?>"
                                download class="btn-cta-secondary"><i data-lucide="download"></i> Descargar PDF</a>
                            <button class="btn-cta-secondary"
                                onclick="Calendly.initPopupWidget({url: 'https://calendly.com/trespuntos/tres-puntos'});return false;"><i
                                    data-lucide="calendar"></i> Agendar videollamada</button>
                        </div>
                        <?php
        else: ?>
                        <div style="margin-bottom:1rem;"><i data-lucide="check-square"
                                style="width:48px;height:48px;color:var(--tp-primary);"></i></div>
                        <h2 style="font-size: 1.8rem; color: var(--tp-primary);">Presupuesto Aprobado</h2>
                        <p>🎉 ¡Gracias por tu confianza! Estamos listos para comenzar con el desarrollo. Nos pondremos
                            en contacto contigo para agendar el kickoff del proyecto.</p>
                        <div class="cta-buttons" style="margin-top: 2rem;">
                            <a href="<?php echo htmlspecialchars($base_path); ?>/uploads/presupuestos/<?php echo htmlspecialchars($proposal['presupuesto_pdf']); ?>"
                                download class="btn-cta-secondary"><i data-lucide="download"></i> Descargar copia
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
                <p>Confirmas que el documento refleja correctamente el alcance y objetivos del proyecto. A partir de
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
                <p>Hemos recibido tu validación y <strong>ya estamos trabajando en el presupuesto</strong>. Te
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
                        data-lucide="message-circle" style="width:48px;height:48px;color:#7B96FF;"></i></div>
                <h4>¡Comentarios enviados!</h4>
                <p>Hemos recibido tus anotaciones. Jordi revisará el documento y te escribirá en breve.</p>
            </div>
        </div>
    </div>

    <!-- Modals for PDF Workflow -->
    <div class="modal-overlay" id="modal-approve-pdf">
        <div class="modal-box">
            <button class="modal-close" onclick="closeModal('approve-pdf')"><i data-lucide="x"
                    style="width:20px;height:20px;"></i></button>
            <div id="approve-pdf-form">
                <div class="modal-icon green"><i data-lucide="check-circle" style="width:28px;height:28px;"></i></div>
                <h3>Aprobar Presupuesto</h3>
                <p>Al confirmar, daremos por cerrado el presupuesto y te explicaremos los siguientes pasos de
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
                <div class="modal-icon blue"><i data-lucide="x-circle" style="width:28px;height:28px;"></i></div>
                <h3>Denegar o Sugerir Cambios</h3>
                <p>Si hay aspectos del presupuesto que quieres modificar, coméntanoslo y lo revisaremos juntos.</p>
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
                        data-lucide="message-circle" style="width:48px;height:48px;color:#7B96FF;"></i></div>
                <h4>¡Mensaje enviado!</h4>
                <p>Analizaremos tus comentarios y te daremos respuesta lo antes posible.</p>
            </div>
        </div>
    </div>

    <!-- Team Data Injection -->
    <script>
        const rawTeamData = <?php echo                             ode($team ?: []); ?>;
        const TEAM_DATA = Array.isArray(raw                            ) ? rawTeamData : [];
        console.log("Team Data parse                             TEAM_DATA);
        
        function injectTeamSection() {
            const TEAM_GR                            m-grid-injected';
            if (document.getElementById(TEAM_GRID_ID)) return;                            double injection

            const area = document.getElementByIde                            ent;
            
            // 1. Prioridad: buscar                             r con ID 'equipo'
            let mountPoint = docum                            ntById('equipo');
                                  amHeader = null;

                                mountPoint) {
                // Si existe el section#equi                                h2/h3 interno
                teamHeader = mountP                            or('h2, h3');
                                     Header) {
                    // Si no tiene header interno, creamos uno pa                                l sidebar
                    teamHeader =                             nt('h2');
                    tea                            'Equipo';
                    mountPoint.prepend(teamHeader);
                }
            } else {
                // 2. Fallback: buscar por texto
                const headers = Array.from(area.querySelectorAll('h2, h3'));
                teamHeader = headers.find(h => {
                    const text = h.innerText ? h.                            e() : '';
                    return text.includes('equipo') || text.includes('quiénes somos') || text.includes('quiénes formamos');
                                 );
            }

            console.log("Team Injection Logic - Mount point:", mountPo                            :", teamHeader);

            if (!TEAM_DATA || TEAM_DATA.length === 0) {
                // Ocultar si no hay datos
                if (mountPoint) mount                            lay = 'none';
                else if (teamHeader) teamH                            lay = 'none';
                                     n;
            }

            // Si llegamos aquí, hay datos. Si no hay ni mountPoint ni teamHeader, lo inyectamos a                            tes de conclusión
                                 teamHeader) {
                console.log("No team header or section fou                            H2 for it.");
                teamHeader =                             lement('h2');
                tea                            t = 'Equipo';
                area.appendChild(teamHeader);
            } else if (teamHeader.tagName === 'H3') {
                // Si encontramos un H3 como header d equipo, lo "ascendemos" a H2 
                // para que el menu lateral lo capture (que solo captura H2)
                const newH2 =                             lement('h2');
                newH2.inne                            er.innerHTML;
                                        eamHeader.id;
                t                            eWith(newH2);
                teamH                            2;
            }

                                  ate the team grid
            const grid = d                            teElement('div');
                                  d = TEAM_GRID_ID;
            grid.className = 'team-grid';
            
            TEAM_DATA.forEach(member => {
                const card = d                            ement('div');
                                                          
                const photoUrl = member.foto_url ? (member.foto_url.startsWith('http') ? member.foto_url : '/' + member.foto_url) : 'https://ui-avatars.com/api/?name=' + encodeURIComponent(member.nombre) + '&background=14                            F&size=512';

                                 rHTML = `
                    <div                                 ner">
                        <img src="${photoUrl}" alt="${me                            g="lazy                               </div>
                                                   nfo">
                        <span class="team-role">${member.carg                                span>
                        <span class="team-                                span>
                        ${member.descripcion ? `<p class="team-desc">${m                            p>` : ''}
                    </div>
                `;
                grid.appendChild(card);
            });

            // Encontrar qué borrar (párrafos de relleno dentro de la sección de equipo)
            if (teamHeader && teamHeader.parentElement && teamHeader.tagName === 'H2' && teamHeader.parentElement.tagName !== 'SECTION') {
                let current = teamHeader.nextElementSibling;
                while (current && (!current.tagName || !['H2', 'H3', 'HR', 'SECTION'].includes(current.tagName))) {
                    let next = current.nextElementSibling;
                    current.remove();
                    current = next;
                }
                teamHeader.after(grid);
            } else if (mountPoint) {
                // Si es un section, borramos párrafos internos (exceptuando el header) y metemos el grid
                const internalElements = Array.from(mountPoint.children);
                internalElements.forEach(el => {
                    if (el.tagName !== 'H2' && el.tagName !== 'H3') el.remove();
                });
                mountPoint.appendChild(grid);
            } else {
                area.appendChild(g;
          }
    </script>

    <!-- Calendly Widget -->
    <link href="https://assets.calendly.com/assets/external/widget.css" rel="stylesheet">
    <script src="https://assets.calendly.com/assets/external/widget.js" type="text/javascript" async></script>

    <script>
        function setupNavigation() {
            const nav = document.g                                        d('sidebar-nav');
            const area = document.ge                                        ('content-area');
            const extArea = document.getElementByIde                                        s');
            
                                            !nav) return [];

            // Find all h2s ins                                        ent area securely
                                            allHeaders                                               if (area) {
                allHeaders = allHeaders.concat(Array.from(area.querySelec                                        ));
                                                       f (extArea) {
                allHeaders = allHeaders.concat(Array.from(extArea.querySelec)                                           }
            
                                          nst tracked = [];
            nav.innerHTML = '<li class="nav-item"><a href="#" class="nav-link active" id="nav-intro"><s                                        span></a></li>';

            const mobileContainer = document.getElement                                        -nav-container');
                                                 eContainer) {
                mobileContainer.innerHTML = '<li class="mobile-nav-item"><a href="#" class="mobile-nav-link" onclick="toggleMobileMenu()"><span>Inicio</sp                                        ';
                                                       try {
                al                                                , i) => {
                    let text = el.innerTexm                                                         
                    // CLEAN NUMBERING: Remove leading "1. ", "2.1. "                                                bar label
                    const navLabel = texs                                                         
                    co                                                erCase();
                    const tag = el.tagName ? el                                                () : '';

                    // Surgical hiding                                                 at start
                    if (i < 5 && (low === "documento funcional" || low === "documentación funcional" || low === "documento funcional aprobado" || low === "presupuesto aprobado" || low                                                    ))) {
                        // Para los mensajes de estado no los ocultamos, solo evitamos meter el ti                                                    ayera
                        if (low === "documento funcional" || low === "documentación funcional" || lo                                                        {
                                                                                 ;
                                                          turn;
                        } else if (low === "documento funcional aprobado" || low                                                         {
                            // no retorna                                                    nd                                                                                                          }

                    // USER REQ                                                ions (H2)
                                                                ) return;
                    if (text.length < 3 || el.style                                                 return;

                    // Preserve existing id if it has one (useful for the extensions which have                                                 2s don't)
                    if                                                ec-' + i;
                                                         ush(el);

                    const li =                                                 nt('li');
                                                                 av-item';
                    const isCTA = low.includes("avanzamos");
                                                             me = isCTA ? 'nav-link                                                link';
                                                                    ref="#${el.id}" class="${className}"><span                                                    ;
                    nav.appendChi                                                         if (mobileContainer) {
                        const mLi = document.createElement('li');
                        mLi                                                    -item';
                        mL                                                ef                                            ss="mobile-nav-link" onclick="                                            ()"><span>${navLabel}</span></a>`;
                        mobil                                        ppendChild(mLi);
                    }
                                                            } catch (e) {
                console.                                         setting up navigation", e)                                              }

            if (window.lucide && window.lucide.createIcons) {
                window                                        teIcons();
            }
            return trac                                         }

        fu                                            ileMenu() {
            const m                                        etElementById('mobileNav');
            const isOpen = m                                            ntains('open');
                                                                  menu.classList.remove('open');
                docum                                        .style.overflow = '';
            } else {
                menu.classList.add('open');
                                                       dy.style.overflow = 'hidden';
            }
                                               let sections = [];
        window.addEventListener('DOMContentLoa                                        { 
                                                              injectTeamSection();
            } catch                                                   console.error("Error Injecting Team Section", e);
                                                
                sections = setupNavigation(); 
                                             ch(e) {
                console.error("Error setting up Navigation", e);
            }
            
            // Ensure icons are created after dynami                                             let iconAttempts = 0;
            const initIcons = () => {
                 if (window.lucide && window.lucide.createIcons) {
                                                                                                             reateIcons();
                    } catch(e) {}
                                                      (iconAttempts < 50) { //                                         5 seconds max
                    iconAttempts++;
                    setTimeout(initIcons, 100);
                                                          };
            initIcons();
                                                     isScrolling = false;
        window.addEventListener('scroll                                                   if (!isScrollin                                                 ionFrame(() => {
                    const scro                                                lY + (window.innerHeight / 3                                                    et current = "                                                   
                                                         w.scrollY < 50) {
                                                               ";
                    } else {
                                                             forEach(el => {
                                                         on                                                    ndin                                                win                                                                     if (offsetTop <= scrollPos) {
                                                            current = el.id;
                                                                                  });
                                                                       // Only toggle active on regular                                                         stays always active)
                                                          uerySelectorAll(".nav-link").forEach(l => {
                        l.classList.remo                                                                  const href = l.ge                                                                                                       (cu"                                                                                                                d("active");
                                                                     nt && l.id === 'nav-intro') {
                            l.classList.add("                                                              }
                                                                        
                    const                                                     lY;
                    const height = document.docume                                                     window.innerHeight;
                    if (                                                  t                                                ll / height) * 100;
                                                                                         document.getElementById("progressFill");
                                                        if (f                                        l.style.width = scrolled + "%";
                    }
                                                      Scrolling = false;
                                                   
                isScrolling = true;
            }
        });

        /                                        logic
        const BOT_TOK                                            9988:AAHZ6UeItc1I6EkULkrV6mozxkLO80t7j58';
        const CHAT_ID = '731343                                               const DOC_NAME = '<?p                                            lashes($proposal["client_name"]); ?>';

        function openModal(type) {
            document.getElementById('modal-' + type).classList.add('open');
        }
        function                                             ype) {
            document.getElementById('modal-' + type).classList.remove('open');
        }
                                            lose on overlay click
        document.querySelectorAll('.modal-overlay').forEach(m => {
            m.addEventLi                                            e => { if (e.tar                                        ssList.remve('open'); });
        });

        f                                        gram(text) {
           return fetch(`https://api.telegram.org/bot${BOT_TOKEN}/sendMessage`, {
                                                method: 'POST',
                heade rs: { 'Content-Type': 'application/json' },
                                                     N.stringify({ chat_id: CHAT_ID, text, p                                        Markdown' })
            });
        }

        async                                        iCall(action, params = {}) {
                                                    ta = new URLSear                                                fomData.append('api_action', action);
            for (le                                        ormData.append(k, params[k]);
                                                    etch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x                                        lencoded' },
                                                : formData
            });
                                               async function submitApproval                                             const btn = document.querySelector('#approve-form .btn-modal-primary');
            btn.disabled = true;
            btn.textContent = 'Enviando...';
            await apiCall('approve_doc');
            await sendTelegram(`✅ *Documento Aprob                                        iente: *${DOC_NAME}*\n\nEl cliente ha aprobado el documento funcional. Preparar presupuesto.`);
            setT                                         window.locaion.reload(), 1000); // Reload to show waiting state
                                               async function submitComment() {
            const text = document.getE                                        comment-text').value.                                                if (!text) { document.get                                        'comment-text').focus(); retur; }
            co                                        cument.querySelector('#comment-form .btn-modal-primary');
            btn.disabled = tr                                            btn.textContent = 'Enviando...';
            await apiCall('                                        , { comment: text });
            await sendTelegram(`💬 *Comentarios del clien                                        ente: *${DOC_NAME}*\n\n${text}`);
            document.getElementById('comment-form').style.display = 'none';
            doc                                        mentById('comment-suc                                        .display = 'block';
        }

                                          function submitPdfApproval() {                                         const btn = document.querySelector('#approve-pdf-form .btn-modal-primary');
            btn.disabled = true;
            btn.textContent = 'Enviando...';
            await apiCall('approve_pdf');
                                            await sendTelegram(`✅💰 *Presupuesto Aprobado*\nCliente: *${DOC_NAME}*\n\n¡El cliente ha aprobado el presupuesto fin                                               setTieout(() => window.location.reload(), 1000); 
        }

                                              tion submitPdfRejection() {
            const text = document.getElementByI                                        f-text').value.trim()                                          if (!text) { document.getElemen                                        t-pdf-text').focus(); return;}
            cons                                        ment.querySelector('#reject-pdf-form .btn-modal-primary');
            btn.disabled = true;
            bt                                        t = 'Enviando...';
            await apiCall('reject_pdf', { commen                                                    await sendTelegram(`❌🧾 *Presupuesto Rechazado/Cambios*\nCl                                        C_NAME}*\n\nComentario: ${text}`);
            docu.getElementById('reject-p-form').style.display = 'none';
            document.getElementById('reject-pdf-success').style.display = 'block';
            if (window.lucide) lucide.createIcons();
        }
    </script>
</body>

</html>
<?php
}

function showError($title, $message)
{
?>

<body
    style="background:#000; color:#FFF; font-family:sans-serif; height:100vh; display:flex; align-items:center; justify-content:center; text-align:center;">
    <div style="max-width: 400px; padding: 2rem;">
        <h1 style="font-size: 1.5rem; margin-bottom: 1rem; color: #5DFFBF;">
            <?php echo $title; ?>
        </h1>
        <p style="color: #888;">
            <?php echo $message; ?>
        </p>
    </div>
</body>
<?php
    exit;
}
?>