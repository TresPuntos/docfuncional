<?php
/**
 * Admin · Sistema de contratos con firma electrónica eIDAS simple.
 *
 * URL: /admin_contratos.php                — listado global
 *      /admin_contratos.php?propuesta_id=X — listado de una propuesta
 *      /admin_contratos.php?contrato_id=X  — detalle + firmar como TP
 *
 * Acciones POST:
 *   - create_from_plantilla
 *   - preview_pdf
 *   - send_to_signer       (genera PDF v0 + envía email al firmante)
 *   - sign_as_tp           (TP firma como contraparte tras el destinatario)
 *   - delete_contrato
 *
 * GET:
 *   - download_pdf=ID&kind=draft|signed
 *   - signers_status=ID
 */

require __DIR__ . '/config.php';
require __DIR__ . '/api/contratos_lib.php';
session_start();

// Auth
// Sesión admin unificada: acepta también la sesión iniciada en admin.php (is_admin).
if (!empty($_SESSION['is_admin']))     { $_SESSION['admin_logged'] = true; }
if (!empty($_SESSION['admin_logged'])) { $_SESSION['is_admin']     = true; }
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
        if ($_POST['admin_password'] === ADMIN_PASSWORD) {
            $_SESSION['admin_logged'] = true;
            header('Location: admin_contratos.php'); exit;
        }
    }
    ?>
    <!doctype html><meta charset="utf-8"><title>Admin · Contratos</title>
    <style>body{background:#0e0e0e;color:#f5f5f5;font-family:system-ui;display:grid;place-items:center;height:100vh;margin:0}form{background:#141414;padding:2rem;border-radius:12px;border:1px solid #1f1f1f;display:grid;gap:.75rem;width:320px}input{background:#191919;border:1px solid #1f1f1f;color:#fff;padding:.6rem;border-radius:6px}button{background:#5dffbf;color:#000;border:none;padding:.6rem;border-radius:6px;font-weight:700;cursor:pointer}</style>
    <form method="post"><strong>Admin Contratos</strong><input name="admin_password" type="password" placeholder="Contraseña" autofocus><button>Entrar</button></form>
    <?php exit;
}

$pdo = getDBConnection();

if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('fecha')) { function fecha($d){ return $d ? date('d/m/Y H:i', strtotime($d)) : '—'; } }

// ====================================================================
//   GET — descarga de PDFs
// ====================================================================
if (isset($_GET['download_pdf'])) {
    $id = (int)$_GET['download_pdf'];
    $kind = $_GET['kind'] ?? 'signed';
    $stmt = $pdo->prepare("SELECT * FROM contratos WHERE id = ?");
    $stmt->execute([$id]);
    $ctr = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ctr) { http_response_code(404); echo 'No existe'; exit; }
    $path = $kind === 'draft' ? $ctr['pdf_sin_firmar_path'] : $ctr['pdf_firmado_path'];
    if (!$path) { http_response_code(404); echo 'PDF no generado todavía'; exit; }
    $abs = __DIR__ . '/' . $path;
    if (!file_exists($abs)) { http_response_code(404); echo 'Archivo no encontrado: ' . e($path); exit; }
    contrato_log_evento($pdo, $id, 'descargado_admin', 'admin');
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="contrato-' . $id . '-' . $kind . '.pdf"');
    header('Content-Length: ' . filesize($abs));
    readfile($abs);
    exit;
}

// ====================================================================
//   POST — acciones
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    // CSRF: todas las acciones mutadoras requieren token válido
    if (!tp_csrf_check('admin_contratos', $_POST['csrf_token'] ?? null)) {
        echo json_encode(['success' => false, 'error' => 'CSRF token inválido. Recarga la página.']); exit;
    }

    $action = $_POST['action'];

    if ($action === 'create_from_plantilla') {
        $plantillaId = (int)($_POST['plantilla_id'] ?? 0);
        $propuestaId = (int)($_POST['propuesta_id'] ?? 0) ?: null;
        $destTipo = $_POST['destinatario_tipo'] ?? '';
        $destId = (int)($_POST['destinatario_id'] ?? 0) ?: null;
        $datos = json_decode($_POST['datos'] ?? '{}', true) ?: [];
        $titulo = trim($_POST['titulo'] ?? '') ?: 'Contrato';

        if (!$plantillaId || !in_array($destTipo, ['cliente','proveedor'], true)) {
            echo json_encode(['success' => false, 'error' => 'Faltan datos']); exit;
        }
        $pStmt = $pdo->prepare("SELECT * FROM contratos_plantillas WHERE id = ? AND activo = 1");
        $pStmt->execute([$plantillaId]);
        $plant = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (!$plant) { echo json_encode(['success' => false, 'error' => 'Plantilla no existe']); exit; }

        // Validación: el destinatario tiene que ser uno de los firmantes declarados en la plantilla
        $firmantesPlant = json_decode($plant['firmantes_json'] ?: '[]', true) ?: [];
        if (!in_array($destTipo, $firmantesPlant, true)) {
            $avail = implode(' o ', array_filter($firmantesPlant, fn($f) => $f !== 'tp'));
            echo json_encode([
                'success' => false,
                'error' => "Esta plantilla está declarada para firmantes: " . implode(', ', $firmantesPlant) . ". No puedes asignarla a un '$destTipo'. Usa otra plantilla o edita los firmantes de '" . $plant['slug'] . "' (admin_plantillas.php).",
            ]);
            exit;
        }

        // Render HTML para hashearlo (lo guardamos como source de verdad)
        $html = contrato_render_template($plant['html_content'], $datos);
        $hash = contrato_hash_data($html);
        $signingToken = bin2hex(random_bytes(16));

        $pdo->prepare("INSERT INTO contratos
            (plantilla_id, propuesta_id, destinatario_tipo, destinatario_id, titulo, datos_json, estado, hash_documento, expira_at, signing_token, signing_token_expires_at)
            VALUES (?, ?, ?, ?, ?, ?, 'borrador', ?, datetime('now', '+' || ? || ' days'), ?, datetime('now', '+' || ? || ' days'))")
            ->execute([
                $plantillaId, $propuestaId, $destTipo, $destId, $titulo,
                json_encode($datos, JSON_UNESCAPED_UNICODE), $hash, SIGN_CONTRACT_EXPIRES_DAYS,
                $signingToken, SIGN_CONTRACT_EXPIRES_DAYS,
            ]);
        $contratoId = (int)$pdo->lastInsertId();
        contrato_log_evento($pdo, $contratoId, 'creado', 'admin', ['plantilla' => $plant['slug']]);

        // Crear slots de firma (uno por firmante declarado en la plantilla)
        $firmantes = json_decode($plant['firmantes_json'], true) ?: ['cliente','tp'];
        foreach ($firmantes as $i => $rol) {
            $datosFirmante = contrato_resolve_firmante_identidad($pdo, $rol, $destId, $propuestaId);
            $pdo->prepare("INSERT INTO contratos_firmas
                (contrato_id, rol, orden, firmante_nombre, firmante_email, firmante_documento, firmante_empresa, firmante_cargo, firmante_direccion)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    $contratoId, $rol, $i + 1,
                    $datosFirmante['firmante_nombre'] ?? null,
                    $datosFirmante['firmante_email'] ?? null,
                    $datosFirmante['firmante_documento'] ?? null,
                    $datosFirmante['firmante_empresa'] ?? null,
                    $datosFirmante['firmante_cargo'] ?? null,
                    $datosFirmante['firmante_direccion'] ?? null,
                ]);
        }

        echo json_encode(['success' => true, 'contrato_id' => $contratoId]);
        exit;
    }

    if ($action === 'create_from_pdf') {
        // One-off: sube un PDF existente (ej. el contrato que Jordi redactó con Claude)
        // y lo firman tal cual sin placeholders.
        $titulo = trim($_POST['titulo'] ?? '') ?: 'Contrato';
        $tipo = trim($_POST['tipo'] ?? 'custom');
        $propuestaId = (int)($_POST['propuesta_id'] ?? 0) ?: null;
        $destTipo = $_POST['destinatario_tipo'] ?? '';
        $destId = (int)($_POST['destinatario_id'] ?? 0) ?: null;
        $firmantes = json_decode($_POST['firmantes'] ?? '[]', true);
        $requireOtp = !empty($_POST['require_otp']) ? 1 : 0;

        if (!in_array($destTipo, ['cliente','proveedor'], true) || !$firmantes || !is_array($firmantes)) {
            echo json_encode(['success' => false, 'error' => 'Faltan datos (destinatario o firmantes)']); exit;
        }
        if (empty($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => 'Sube un archivo PDF válido']); exit;
        }
        if ($_FILES['pdf']['size'] > 20 * 1024 * 1024) {
            echo json_encode(['success' => false, 'error' => 'El PDF no puede superar 20 MB']); exit;
        }
        // Validación de tipo REAL con finfo + magic bytes (no confiar en $_FILES[..]['type'] ni extensión)
        $realMime = function_exists('finfo_file')
            ? finfo_file(finfo_open(FILEINFO_MIME_TYPE), $_FILES['pdf']['tmp_name'])
            : mime_content_type($_FILES['pdf']['tmp_name']);
        if ($realMime !== 'application/pdf') {
            echo json_encode(['success' => false, 'error' => 'El archivo no es un PDF válido (detectado: ' . e($realMime ?: 'desconocido') . ')']); exit;
        }
        $head = file_get_contents($_FILES['pdf']['tmp_name'], false, null, 0, 5);
        if ($head !== '%PDF-') {
            echo json_encode(['success' => false, 'error' => 'El archivo no parece un PDF (magic bytes incorrectos)']); exit;
        }

        // Insertar registro sin plantilla_id (con signing_token)
        $signingToken = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO contratos
            (plantilla_id, propuesta_id, destinatario_tipo, destinatario_id, titulo, datos_json, estado, hash_documento, expira_at, signing_token, signing_token_expires_at)
            VALUES (NULL, ?, ?, ?, ?, NULL, 'borrador', '', datetime('now', '+' || ? || ' days'), ?, datetime('now', '+' || ? || ' days'))")
            ->execute([$propuestaId, $destTipo, $destId, $titulo, SIGN_CONTRACT_EXPIRES_DAYS, $signingToken, SIGN_CONTRACT_EXPIRES_DAYS]);
        $contratoId = (int)$pdo->lastInsertId();

        // Guardar el PDF subido
        $dir = __DIR__ . '/uploads/contratos/' . $contratoId;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $destPath = $dir . '/uploaded.pdf';
        if (!move_uploaded_file($_FILES['pdf']['tmp_name'], $destPath)) {
            $pdo->prepare("DELETE FROM contratos WHERE id = ?")->execute([$contratoId]);
            echo json_encode(['success' => false, 'error' => 'No se pudo guardar el PDF']); exit;
        }

        // Hash del PDF subido (fuente de verdad para el contrato one-off)
        $hash = contrato_hash_file($destPath);
        $relPath = 'uploads/contratos/' . $contratoId . '/uploaded.pdf';

        // Guardamos una clave especial en datos_json que indica que es PDF directo
        // Además guardamos tipo y require_otp flags
        $meta = ['mode' => 'pdf_direct', 'tipo' => $tipo, 'require_otp' => $requireOtp, 'original_name' => $_FILES['pdf']['name']];
        $pdo->prepare("UPDATE contratos SET hash_documento = ?, pdf_sin_firmar_path = ?, datos_json = ? WHERE id = ?")
            ->execute([$hash, $relPath, json_encode($meta, JSON_UNESCAPED_UNICODE), $contratoId]);

        contrato_log_evento($pdo, $contratoId, 'creado', 'admin', ['modo' => 'pdf_direct', 'archivo' => $_FILES['pdf']['name']]);

        // Crear slots de firma
        foreach ($firmantes as $i => $rol) {
            if (!in_array($rol, ['cliente','proveedor','tp'], true)) continue;
            $datosFirmante = contrato_resolve_firmante_identidad($pdo, $rol, $destId, $propuestaId);
            $pdo->prepare("INSERT INTO contratos_firmas
                (contrato_id, rol, orden, firmante_nombre, firmante_email, firmante_documento, firmante_empresa, firmante_cargo, firmante_direccion)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    $contratoId, $rol, $i + 1,
                    $datosFirmante['firmante_nombre'] ?? null,
                    $datosFirmante['firmante_email'] ?? null,
                    $datosFirmante['firmante_documento'] ?? null,
                    $datosFirmante['firmante_empresa'] ?? null,
                    $datosFirmante['firmante_cargo'] ?? null,
                    $datosFirmante['firmante_direccion'] ?? null,
                ]);
        }

        echo json_encode(['success' => true, 'contrato_id' => $contratoId]);
        exit;
    }

    if ($action === 'send_to_signer') {
        $id = (int)($_POST['contrato_id'] ?? 0);
        $forceEmail = trim($_POST['force_email'] ?? '');
        $stmt = $pdo->prepare("SELECT * FROM contratos WHERE id = ?");
        $stmt->execute([$id]);
        $ctr = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ctr) { echo json_encode(['success' => false, 'error' => 'No existe']); exit; }

        // Máquina de estados: solo permitimos enviar desde borrador o re-enviar desde enviado/visto.
        // NUNCA desde firmado / firmado_parcial / rechazado / expirado.
        if (!in_array($ctr['estado'], ['borrador','enviado','visto'], true)) {
            echo json_encode(['success' => false, 'error' => 'No se puede enviar desde el estado actual (' . $ctr['estado'] . ')']); exit;
        }

        // Asegurar signing_token (por si el contrato se creó antes de la migración)
        if (empty($ctr['signing_token'])) {
            $tok = bin2hex(random_bytes(16));
            $pdo->prepare("UPDATE contratos SET signing_token = ?, signing_token_expires_at = datetime('now', '+' || ? || ' days') WHERE id = ?")
                ->execute([$tok, SIGN_CONTRACT_EXPIRES_DAYS, $id]);
            $ctr['signing_token'] = $tok;
        }

        // CASO A · PDF directo: no regenera v0 (solo bumpea a 'enviado' si estaba en borrador)
        if (empty($ctr['plantilla_id'])) {
            if ($ctr['estado'] === 'borrador') {
                $pdo->prepare("UPDATE contratos SET estado = 'enviado', enviado_at = datetime('now') WHERE id = ?")->execute([$id]);
            }
            contrato_log_evento($pdo, $id, 'enviado', 'admin', ['modo' => 'pdf_direct']);
        } else {
            // CASO B · Plantilla HTML: generamos v0 sin firmas
            $plant = $pdo->prepare("SELECT * FROM contratos_plantillas WHERE id = ?");
            $plant->execute([$ctr['plantilla_id']]);
            $plant = $plant->fetch(PDO::FETCH_ASSOC);
            $datos = json_decode($ctr['datos_json'], true) ?: [];
            $html = contrato_render_template($plant['html_content'], $datos);
            $dir = __DIR__ . '/uploads/contratos/' . $id;
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $pdfPath = $dir . '/v0_sin_firmar.pdf';
            contrato_generate_pdf($html, [], ['titulo' => $ctr['titulo'], 'tipo' => $plant['tipo'], 'hash_documento' => $ctr['hash_documento']], $pdfPath);
            $relPath = 'uploads/contratos/' . $id . '/v0_sin_firmar.pdf';
            if ($ctr['estado'] === 'borrador') {
                $pdo->prepare("UPDATE contratos SET pdf_sin_firmar_path = ?, estado = 'enviado', enviado_at = datetime('now') WHERE id = ?")
                    ->execute([$relPath, $id]);
            } else {
                $pdo->prepare("UPDATE contratos SET pdf_sin_firmar_path = ? WHERE id = ?")
                    ->execute([$relPath, $id]);
            }
            contrato_log_evento($pdo, $id, 'enviado', 'admin');
        }

        // Enviar email al/los firmante/s destinatario (acepta uno o varios emails separados por coma)
        $emailSent = false; $emailTo = []; $emailError = null; $emailInvalid = [];
        $slotStmt = $pdo->prepare("SELECT firmante_nombre, firmante_email FROM contratos_firmas WHERE contrato_id = ? AND rol = ? LIMIT 1");
        $slotStmt->execute([$id, $ctr['destinatario_tipo']]);
        $slot = $slotStmt->fetch(PDO::FETCH_ASSOC);

        $rawEmailsInput = $forceEmail !== '' ? $forceEmail : ($slot['firmante_email'] ?? '');
        if ($rawEmailsInput) {
            $parsed = tp_parse_email_list($rawEmailsInput);
            $emailTo = $parsed['valid'];
            $emailInvalid = $parsed['invalid'];
        }

        $host = $_SERVER['HTTP_HOST'] ?? 'doc.trespuntos-lab.com';
        $scheme = (($_SERVER['HTTPS'] ?? '') === 'on' || strpos($host, 'localhost') === false) ? 'https' : 'http';
        $signUrl = $scheme . '://' . $host . '/sign.php?token=' . urlencode($ctr['signing_token']);

        if (!empty($emailTo)) {
            // Guardar lista canónica en firmante_email (separados por coma) y dejar el primero accesible
            $canonList = implode(', ', $emailTo);
            if ($slot && $slot['firmante_email'] !== $canonList) {
                $pdo->prepare("UPDATE contratos_firmas SET firmante_email = ? WHERE contrato_id = ? AND rol = ?")
                    ->execute([$canonList, $id, $ctr['destinatario_tipo']]);
            }
            $emailSent = contrato_send_invite_email($emailTo, $slot['firmante_nombre'] ?? '', $ctr['titulo'], $signUrl);
            contrato_log_evento($pdo, $id, $emailSent ? 'email_invite_enviado' : 'email_invite_fallido', 'admin', ['to' => $emailTo, 'invalid' => $emailInvalid]);
        } else {
            $emailError = $emailInvalid ? ('Ningún email válido · inválidos: ' . implode(', ', $emailInvalid)) : 'Sin email · copia el link manualmente';
        }

        echo json_encode([
            'success' => true,
            'email_sent' => $emailSent,
            'email_to' => $emailTo,
            'email_invalid' => $emailInvalid,
            'email_error' => $emailError,
            'sign_url' => $signUrl,
        ]);
        exit;
    }

    if ($action === 'resend_email') {
        $id = (int)($_POST['contrato_id'] ?? 0);
        $emailRaw = trim($_POST['email'] ?? '');
        $parsed = tp_parse_email_list($emailRaw);
        if (empty($parsed['valid'])) {
            echo json_encode(['success' => false, 'error' => 'Ningún email válido' . ($parsed['invalid'] ? ' · inválidos: ' . implode(', ', $parsed['invalid']) : '')]);
            exit;
        }
        $ctr = $pdo->prepare("SELECT * FROM contratos WHERE id = ?");
        $ctr->execute([$id]);
        $ctr = $ctr->fetch(PDO::FETCH_ASSOC);
        if (!$ctr) { echo json_encode(['success' => false, 'error' => 'No existe']); exit; }
        if (empty($ctr['signing_token'])) {
            $tok = bin2hex(random_bytes(16));
            $pdo->prepare("UPDATE contratos SET signing_token = ? WHERE id = ?")->execute([$tok, $id]);
            $ctr['signing_token'] = $tok;
        }
        $canonList = implode(', ', $parsed['valid']);
        $pdo->prepare("UPDATE contratos_firmas SET firmante_email = ? WHERE contrato_id = ? AND rol = ?")
            ->execute([$canonList, $id, $ctr['destinatario_tipo']]);
        $slot = $pdo->prepare("SELECT firmante_nombre FROM contratos_firmas WHERE contrato_id = ? AND rol = ? LIMIT 1");
        $slot->execute([$id, $ctr['destinatario_tipo']]);
        $nombre = $slot->fetchColumn() ?: '';
        $host = $_SERVER['HTTP_HOST'] ?? 'doc.trespuntos-lab.com';
        $scheme = (($_SERVER['HTTPS'] ?? '') === 'on' || strpos($host, 'localhost') === false) ? 'https' : 'http';
        $signUrl = $scheme . '://' . $host . '/sign.php?token=' . urlencode($ctr['signing_token']);
        $ok = contrato_send_invite_email($parsed['valid'], $nombre, $ctr['titulo'], $signUrl);
        contrato_log_evento($pdo, $id, $ok ? 'email_invite_reenviado' : 'email_invite_reenvio_fallido', 'admin', ['to' => $parsed['valid'], 'invalid' => $parsed['invalid']]);
        echo json_encode([
            'success' => $ok,
            'email_to' => $parsed['valid'],
            'email_invalid' => $parsed['invalid'],
            'sign_url' => $signUrl,
        ]);
        exit;
    }

    if ($action === 'sign_as_tp') {
        $id = (int)($_POST['contrato_id'] ?? 0);
        $trazo = $_POST['trazo_base64'] ?? '';
        $consent = !empty($_POST['consent']);
        if (!$id || !$trazo || !$consent) { echo json_encode(['success' => false, 'error' => 'Faltan datos']); exit; }

        // Validar trazo (tamaño + formato PNG real)
        $trazoCheck = tp_validate_signature_trazo($trazo);
        if (!$trazoCheck['ok']) { echo json_encode(['success' => false, 'error' => 'Firma no válida: ' . $trazoCheck['reason']]); exit; }

        $ctr = $pdo->prepare("SELECT * FROM contratos WHERE id = ?");
        $ctr->execute([$id]);
        $ctr = $ctr->fetch(PDO::FETCH_ASSOC);
        if (!$ctr) { echo json_encode(['success' => false, 'error' => 'No existe']); exit; }

        // Solo firmable si está en estado que permita firma
        if (!in_array($ctr['estado'], ['enviado','visto','firmado_parcial'], true)) {
            echo json_encode(['success' => false, 'error' => 'No se puede firmar en el estado actual (' . $ctr['estado'] . ')']); exit;
        }

        // TRANSACCIÓN: todo el bloque de firma + check pendientes + generación PDF
        // debe ser atómico para evitar race conditions (2 firmas concurrentes → doble PDF).
        try {
            $pdo->beginTransaction();
            // Buscar slot 'tp' sin firmar
            $tpFirma = $pdo->prepare("SELECT * FROM contratos_firmas WHERE contrato_id = ? AND rol = 'tp' AND firmado_at IS NULL");
            $tpFirma->execute([$id]);
            $f = $tpFirma->fetch(PDO::FETCH_ASSOC);
            if (!$f) { $pdo->rollBack(); echo json_encode(['success' => false, 'error' => 'No hay slot de firma TP pendiente']); exit; }

            $consentText = contrato_consent_text($ctr['titulo']);
            $ip = contrato_client_ip();
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $now = gmdate('Y-m-d H:i:s');
            $hashFirma = hash('sha256', $ctr['hash_documento'] . ($f['firmante_email'] ?: TP_FIRMANTE_EMAIL) . $now . $ip . random_bytes(16));

            $upd = $pdo->prepare("UPDATE contratos_firmas SET
                firma_trazo_base64 = ?, firma_hash = ?, ip = ?, geoip_country = ?, user_agent = ?,
                consent_texto = ?, consent_aceptado = 1, signing_method = 'trazo',
                signing_duration_ms = ?, scroll_depth_pct = ?,
                server_timestamp_utc = ?, client_timestamp = ?,
                firmado_at = datetime('now')
                WHERE id = ? AND firmado_at IS NULL");
            $upd->execute([
                $trazo, $hashFirma, $ip, contrato_resolve_country($ip), $ua,
                $consentText,
                (int)($_POST['signing_duration_ms'] ?? 0),
                (int)($_POST['scroll_depth_pct'] ?? 100),
                $now, $_POST['client_timestamp'] ?? null,
                $f['id'],
            ]);
            if ($upd->rowCount() === 0) { $pdo->rollBack(); echo json_encode(['success' => false, 'error' => 'La firma ya se registró por otra vía']); exit; }

            $pendientes = $pdo->prepare("SELECT COUNT(*) FROM contratos_firmas WHERE contrato_id = ? AND firmado_at IS NULL");
            $pendientes->execute([$id]);
            $completed = (int)$pendientes->fetchColumn() === 0;
            if (!$completed) {
                $pdo->prepare("UPDATE contratos SET estado = 'firmado_parcial' WHERE id = ?")->execute([$id]);
            }
            $pdo->commit();
        } catch (\Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Error interno al registrar firma']); exit;
        }

        contrato_log_evento($pdo, $id, 'firmado_tp', TP_FIRMANTE_EMAIL);

        if ($completed) {
            // Generación PDF fuera de la transacción (TSA puede tardar 8s)
            $finalPath = generar_pdf_final($pdo, $id);
            echo json_encode(['success' => true, 'completed' => true, 'final_pdf' => $finalPath]);
        } else {
            echo json_encode(['success' => true, 'completed' => false]);
        }
        exit;
    }

    if ($action === 'delete_contrato') {
        $id = (int)($_POST['contrato_id'] ?? 0);
        // No permitimos borrar contratos firmados (retención legal 6 años)
        $estado = $pdo->prepare("SELECT estado FROM contratos WHERE id = ?");
        $estado->execute([$id]);
        $st = $estado->fetchColumn();
        if (!$st) { echo json_encode(['success' => false, 'error' => 'No existe']); exit; }
        if ($st === 'firmado') {
            echo json_encode(['success' => false, 'error' => 'No se puede borrar un contrato firmado. Arquívalo en su lugar.']); exit;
        }
        $pdo->prepare("DELETE FROM contratos WHERE id = ?")->execute([$id]);
        // Limpieza archivos asociados
        $dir = __DIR__ . '/uploads/contratos/' . $id;
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') as $f) @unlink($f);
            @rmdir($dir);
        }
        contrato_log_evento($pdo, $id, 'borrado', 'admin', ['estado_previo' => $st]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Acción desconocida']);
    exit;
}

// ====================================================================
//   Helper: generar PDF final firmado (con audit trail)
// ====================================================================
function generar_pdf_final(PDO $pdo, int $contratoId): string {
    $ctr = $pdo->prepare("SELECT c.*, p.html_content, p.tipo AS plantilla_tipo FROM contratos c LEFT JOIN contratos_plantillas p ON p.id = c.plantilla_id WHERE c.id = ?");
    $ctr->execute([$contratoId]);
    $row = $ctr->fetch(PDO::FETCH_ASSOC);

    $firmas = $pdo->prepare("SELECT * FROM contratos_firmas WHERE contrato_id = ? ORDER BY orden ASC");
    $firmas->execute([$contratoId]);
    $firmasArr = $firmas->fetchAll(PDO::FETCH_ASSOC);

    $tsa = contrato_request_tsa_timestamp($row['hash_documento']);

    $dir = __DIR__ . '/uploads/contratos/' . $contratoId;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $finalPath = $dir . '/v_final.pdf';

    $meta = [
        'titulo' => $row['titulo'],
        'tipo' => $row['plantilla_tipo'] ?? (json_decode($row['datos_json'] ?: '{}', true)['tipo'] ?? 'custom'),
        'hash_documento' => $row['hash_documento'],
        'tsa_timestamp' => $tsa,
    ];

    if (empty($row['plantilla_id']) && !empty($row['pdf_sin_firmar_path'])) {
        // CASO A · PDF directo: apilar PDF subido + hoja audit trail al final (FPDI)
        $basePath = __DIR__ . '/' . $row['pdf_sin_firmar_path'];
        contrato_stamp_pdf_with_audit($basePath, $firmasArr, $meta, $finalPath);
    } else {
        // CASO B · Plantilla HTML: renderizar + audit trail
        $datos = json_decode($row['datos_json'], true) ?: [];
        $html = contrato_render_template($row['html_content'], $datos);
        contrato_generate_pdf($html, $firmasArr, $meta, $finalPath);
    }

    $rel = 'uploads/contratos/' . $contratoId . '/v_final.pdf';
    $hashFinal = contrato_hash_file($finalPath);

    $pdo->prepare("UPDATE contratos SET pdf_firmado_path = ?, hash_final = ?, estado = 'firmado', firmado_at = datetime('now') WHERE id = ?")
        ->execute([$rel, $hashFinal, $contratoId]);

    if ($tsa) {
        $pdo->prepare("UPDATE contratos_firmas SET tsa_timestamp = ? WHERE contrato_id = ?")->execute([$tsa, $contratoId]);
    }

    contrato_log_evento($pdo, $contratoId, 'pdf_final_generado', 'sistema', ['hash_final' => substr($hashFinal, 0, 12), 'modo' => empty($row['plantilla_id']) ? 'pdf_direct' : 'plantilla']);
    return $rel;
}

// ====================================================================
//   GET — datos para la vista
// ====================================================================
$contratoIdView = isset($_GET['contrato_id']) ? (int)$_GET['contrato_id'] : null;
$propuestaIdFilter = isset($_GET['propuesta_id']) ? (int)$_GET['propuesta_id'] : null;
$estadoFilter = $_GET['estado'] ?? 'todos';

$plantillas = $pdo->query("SELECT * FROM contratos_plantillas WHERE activo = 1 ORDER BY tipo, nombre")->fetchAll(PDO::FETCH_ASSOC);

// Lista de contratos con join contraparte
$where = ['1=1'];
$params = [];
if ($propuestaIdFilter) { $where[] = "c.propuesta_id = ?"; $params[] = $propuestaIdFilter; }
if ($estadoFilter !== 'todos') { $where[] = "c.estado = ?"; $params[] = $estadoFilter; }
$qContratos = $pdo->prepare("
    SELECT c.*, p.nombre AS plantilla_nombre, p.tipo AS plantilla_tipo,
           pr.client_name, pr.slug AS propuesta_slug,
           pv.nombre AS proveedor_nombre, pv.empresa AS proveedor_empresa, pv.email AS proveedor_email,
           cl.nombre AS cliente_nombre, cl.empresa AS cliente_empresa, cl.email AS cliente_email
    FROM contratos c
    LEFT JOIN contratos_plantillas p ON p.id = c.plantilla_id
    LEFT JOIN propuestas pr ON pr.id = c.propuesta_id
    LEFT JOIN propuesta_proveedores pv ON pv.id = c.destinatario_id AND c.destinatario_tipo = 'proveedor'
    LEFT JOIN propuesta_clientes cl    ON cl.id = c.destinatario_id AND c.destinatario_tipo = 'cliente'
    WHERE " . implode(' AND ', $where) . "
    ORDER BY c.created_at DESC
");
$qContratos->execute($params);
$contratos = $qContratos->fetchAll(PDO::FETCH_ASSOC);

// Datos para selects del modal "nuevo contrato"
$propuestasDisponibles = $pdo->query("SELECT id, slug, client_name FROM propuestas WHERE status = 1 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$proveedoresDisponibles = $pdo->query("
    SELECT pv.id, pv.nombre, pv.empresa, pv.email, pv.propuesta_id, pr.client_name
    FROM propuesta_proveedores pv
    LEFT JOIN propuestas pr ON pr.id = pv.propuesta_id
    WHERE pv.activo = 1
    ORDER BY pr.id DESC, pv.nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Clientes firmantes (defensivo: tabla puede no existir si migrate_clientes.php no se aplicó)
$clientesDisponibles = [];
try {
    $clientesDisponibles = $pdo->query("
        SELECT cl.id, cl.nombre, cl.empresa, cl.email, cl.propuesta_id, pr.client_name, pr.slug
        FROM propuesta_clientes cl
        LEFT JOIN propuestas pr ON pr.id = cl.propuesta_id
        WHERE cl.activo = 1
        ORDER BY pr.id DESC, cl.nombre ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { /* tabla aún no migrada en prod */ }

// Si se pide vista detalle
$contratoView = null;
$firmasView = [];
$eventosView = [];
if ($contratoIdView) {
    $stmt = $pdo->prepare("
        SELECT c.*, p.nombre AS plantilla_nombre, p.tipo AS plantilla_tipo,
               pr.client_name, pr.slug AS propuesta_slug,
               pv.nombre AS proveedor_nombre, pv.empresa AS proveedor_empresa,
               cl.nombre AS cliente_nombre, cl.empresa AS cliente_empresa, cl.email AS cliente_email
        FROM contratos c
        LEFT JOIN contratos_plantillas p ON p.id = c.plantilla_id
        LEFT JOIN propuestas pr ON pr.id = c.propuesta_id
        LEFT JOIN propuesta_proveedores pv ON pv.id = c.destinatario_id AND c.destinatario_tipo = 'proveedor'
        LEFT JOIN propuesta_clientes cl    ON cl.id = c.destinatario_id AND c.destinatario_tipo = 'cliente'
        WHERE c.id = ?
    ");
    $stmt->execute([$contratoIdView]);
    $contratoView = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($contratoView) {
        $f = $pdo->prepare("SELECT * FROM contratos_firmas WHERE contrato_id = ? ORDER BY orden ASC");
        $f->execute([$contratoIdView]);
        $firmasView = $f->fetchAll(PDO::FETCH_ASSOC);
        $ev = $pdo->prepare("SELECT * FROM contratos_eventos WHERE contrato_id = ? ORDER BY created_at DESC LIMIT 30");
        $ev->execute([$contratoIdView]);
        $eventosView = $ev->fetchAll(PDO::FETCH_ASSOC);
    }
}

$csrfToken = tp_csrf_token('admin_contratos');

// KPIs
$kpis = [
    'total'    => (int)$pdo->query("SELECT COUNT(*) FROM contratos")->fetchColumn(),
    'pendientes' => (int)$pdo->query("SELECT COUNT(*) FROM contratos WHERE estado IN ('enviado','visto','firmado_parcial')")->fetchColumn(),
    'firmados' => (int)$pdo->query("SELECT COUNT(*) FROM contratos WHERE estado = 'firmado'")->fetchColumn(),
    'plantillas' => count($plantillas),
];

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Contratos · Tres Puntos Admin</title>
<style>
:root {
    --mint: #5dffbf; --mint-rgb: 93,255,191; --mint-hover: #49e6a8;
    --bg-base: #0e0e0e; --bg-surface: #141414; --bg-subtle: #191919; --bg-muted: #1f1f1f;
    --text-primary: #f5f5f5; --text-secondary: #b3b3b3; --text-muted: #8a8a8a;
    --border-base: #1f1f1f; --border-subtle: #1a1a1a;
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg-base); color: var(--text-primary); font-family: 'Inter', system-ui, sans-serif; font-size: 14px; }
.admin-layout { display: grid; grid-template-columns: 272px 1fr; min-height: 100vh; }
.admin-main { padding: 1.5rem 2rem; overflow-x: hidden; }
.admin-main-header { display: flex; justify-content: space-between; align-items: center; margin: 1rem 0 1.5rem 0; }
.admin-main-title { font-size: 1.6rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: .55rem; letter-spacing: -.015em; }
.admin-main-title small { font-weight: 400; color: var(--text-muted); font-size: 1rem; }
.admin-main-actions { display: flex; gap: .5rem; }
.btn { background: var(--bg-muted); color: var(--text-primary); border: 1px solid var(--border-base); padding: .55rem 1rem; border-radius: 8px; cursor: pointer; font-size: .85rem; font-weight: 500; display: inline-flex; align-items: center; gap: .4rem; text-decoration: none; }
.btn:hover { background: var(--bg-subtle); border-color: var(--mint); color: var(--mint); }
.btn-primary { background: var(--mint); color: #000; border-color: var(--mint); font-weight: 600; }
.btn-primary:hover { background: var(--mint-hover); border-color: var(--mint-hover); color: #000; }
.btn-ghost { background: transparent; }
.btn-danger { color: #ff6b6b; border-color: #3a1f1f; }
.btn-danger:hover { background: #1f0d0d; border-color: #ff6b6b; color: #ff8a8a; }

.kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 2rem; }
.kpi { background: var(--bg-surface); border: 1px solid var(--border-base); padding: 1rem 1.2rem; border-radius: 12px; }
.kpi-label { font-size: .7rem; text-transform: uppercase; letter-spacing: .12em; color: var(--text-muted); font-weight: 600; }
.kpi-value { font-size: 2rem; font-weight: 700; margin-top: .35rem; line-height: 1; font-variant-numeric: tabular-nums; }

.card { background: var(--bg-surface); border: 1px solid var(--border-base); border-radius: 12px; padding: 1.4rem; margin-bottom: 1.5rem; }
.card h2 { font-size: 1.05rem; font-weight: 600; margin: 0 0 1rem 0; display: flex; align-items: center; gap: .5rem; }

.tabs { display: flex; gap: .25rem; border-bottom: 1px solid var(--border-base); margin-bottom: 1.4rem; }
.tab { padding: .65rem 1rem; cursor: pointer; color: var(--text-muted); border: none; background: transparent; font-size: .88rem; font-weight: 500; border-bottom: 2px solid transparent; margin-bottom: -1px; }
.tab.active { color: var(--mint); border-bottom-color: var(--mint); }
.tab-panel { display: none; }
.tab-panel.active { display: block; }

table.contratos { width: 100%; border-collapse: collapse; font-size: .85rem; }
table.contratos th { text-align: left; font-weight: 600; color: var(--text-muted); font-size: .68rem; text-transform: uppercase; letter-spacing: .1em; padding: .6rem .75rem; border-bottom: 1px solid var(--border-base); }
table.contratos td { padding: .8rem .75rem; border-bottom: 1px solid var(--border-subtle); vertical-align: middle; }
table.contratos tr:hover td { background: var(--bg-subtle); }

.pill { display: inline-flex; align-items: center; gap: .3rem; padding: .2rem .55rem; border-radius: 99px; font-size: .68rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
.pill-borrador { background: #1f1f1f; color: #b3b3b3; }
.pill-enviado { background: #1a2a3a; color: #6abfff; }
.pill-visto { background: #2a2a1a; color: #ffd86a; }
.pill-firmado_parcial { background: #2a1f3a; color: #c89eff; }
.pill-firmado { background: rgba(93,255,191,.15); color: var(--mint); }
.pill-rechazado { background: #2a1717; color: #ff7d7d; }
.pill-expirado { background: #2a2018; color: #ffa66a; }

.tipo-chip { background: var(--bg-muted); color: var(--text-secondary); padding: .12rem .5rem; border-radius: 4px; font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }

.contraparte { display: flex; align-items: center; gap: .55rem; }
.contraparte .avatar { width: 28px; height: 28px; border-radius: 50%; background: var(--bg-muted); display: grid; place-items: center; font-weight: 700; font-size: .75rem; color: var(--mint); flex-shrink: 0; }
.contraparte .meta { line-height: 1.3; }
.contraparte .name { font-weight: 600; color: var(--text-primary); font-size: .85rem; }
.contraparte .email { color: var(--text-muted); font-size: .73rem; }

.actions { display: flex; gap: .35rem; justify-content: flex-end; }
.icon-btn { background: transparent; border: 1px solid transparent; color: var(--text-muted); padding: .35rem .5rem; border-radius: 6px; cursor: pointer; }
.icon-btn:hover { color: var(--mint); border-color: var(--border-base); background: var(--bg-subtle); }

.empty { text-align: center; padding: 3rem 1rem; color: var(--text-muted); }

.filters { display: flex; gap: .35rem; margin-bottom: 1.2rem; }
.filter-pill { padding: .35rem .85rem; border-radius: 99px; background: var(--bg-muted); border: 1px solid var(--border-base); cursor: pointer; font-size: .78rem; color: var(--text-secondary); text-decoration: none; }
.filter-pill.active { background: var(--mint); color: #000; border-color: var(--mint); font-weight: 600; }

/* Modal */
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.7); display: none; align-items: center; justify-content: center; z-index: 100; padding: 2rem; }
.modal-backdrop.open { display: flex; }
.modal { background: var(--bg-surface); border: 1px solid var(--border-base); border-radius: 14px; max-width: 720px; width: 100%; max-height: 92vh; overflow-y: auto; }
.modal-head { padding: 1.2rem 1.5rem; border-bottom: 1px solid var(--border-base); display: flex; justify-content: space-between; align-items: center; }
.modal-head h2 { margin: 0; font-size: 1.1rem; }
.modal-body { padding: 1.5rem; }
.modal-footer { padding: 1rem 1.5rem; border-top: 1px solid var(--border-base); display: flex; justify-content: flex-end; gap: .5rem; }

.field { margin-bottom: 1rem; }
.field label { display: block; font-size: .78rem; color: var(--text-muted); margin-bottom: .35rem; font-weight: 500; }
.field input, .field select, .field textarea { width: 100%; background: var(--bg-subtle); border: 1px solid var(--border-base); color: var(--text-primary); padding: .6rem .75rem; border-radius: 8px; font-size: .85rem; font-family: inherit; }
.field textarea { min-height: 80px; resize: vertical; }
.field input:focus, .field select:focus, .field textarea:focus { outline: none; border-color: var(--mint); }
.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }

/* Detalle */
.detail-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; }
.firma-row { padding: .85rem 1rem; background: var(--bg-subtle); border-radius: 10px; margin-bottom: .55rem; display: flex; align-items: center; gap: .85rem; }
.firma-row .rol-badge { background: var(--bg-muted); color: var(--text-secondary); padding: .15rem .55rem; border-radius: 5px; font-size: .68rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
.firma-row .rol-tp { color: var(--mint); }
.firma-row .name { flex: 1; font-weight: 600; font-size: .85rem; }
.firma-row .email { color: var(--text-muted); font-size: .75rem; }
.firma-row .status { font-size: .7rem; color: var(--text-muted); }
.firma-row.signed { border-left: 2px solid var(--mint); }
.firma-row.signed .status { color: var(--mint); }

.timeline { font-size: .8rem; }
.timeline-item { display: flex; gap: .75rem; padding: .5rem 0; border-bottom: 1px solid var(--border-subtle); }
.timeline-item:last-child { border-bottom: none; }
.timeline-time { color: var(--text-muted); font-size: .7rem; min-width: 80px; }

#sigPad { background: #fff; border-radius: 8px; touch-action: none; cursor: crosshair; }
</style>
</head>
<body>
<?php include __DIR__ . '/master/admin-faceid.php'; ?>

<?php
$adminSidebarActive = 'contratos';
$adminSidebarPropuestas = $pdo->query("SELECT id, slug, client_name FROM propuestas WHERE status = 1 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="admin-layout">
<?php include __DIR__ . '/master/admin-sidebar.php'; ?>

<main class="admin-main">
<?php
$adminBreadcrumbItems = [
    ['label' => 'Dashboard', 'href' => 'admin.php'],
    ['label' => 'Contratos', 'href' => $contratoIdView ? 'admin_contratos.php' : null],
];
if ($contratoIdView && $contratoView) {
    $adminBreadcrumbItems[] = ['label' => e($contratoView['titulo']), 'href' => null];
}
@include __DIR__ . '/master/admin-breadcrumb.php';
?>

<?php if (!$contratoIdView): // Listado global ?>

<div class="admin-main-header">
    <h1 class="admin-main-title">
        <i data-lucide="file-signature"></i>
        Contratos
        <small>· firma electrónica eIDAS</small>
    </h1>
    <div class="admin-main-actions">
        <button class="btn btn-primary" onclick="openCreateModal()">
            <i data-lucide="plus" style="width:14px;height:14px"></i> Nuevo contrato
        </button>
    </div>
</div>

<div class="kpi-row">
    <div class="kpi"><div class="kpi-label">Total</div><div class="kpi-value"><?= $kpis['total'] ?></div></div>
    <div class="kpi"><div class="kpi-label">Pendientes firma</div><div class="kpi-value" style="color:#ffd86a"><?= $kpis['pendientes'] ?></div></div>
    <div class="kpi"><div class="kpi-label">Firmados</div><div class="kpi-value" style="color:var(--mint)"><?= $kpis['firmados'] ?></div></div>
    <div class="kpi"><div class="kpi-label">Plantillas activas</div><div class="kpi-value"><?= $kpis['plantillas'] ?></div></div>
</div>

<div class="filters">
    <?php foreach ([['todos','Todos'], ['borrador','Borrador'], ['enviado','Enviado'], ['firmado_parcial','Pendiente otra firma'], ['firmado','Firmados'], ['expirado','Expirados']] as $f): ?>
    <a class="filter-pill <?= $estadoFilter === $f[0] ? 'active' : '' ?>" href="?estado=<?= $f[0] ?>"><?= $f[1] ?></a>
    <?php endforeach; ?>
</div>

<div class="card" style="padding:0">
<?php if (empty($contratos)): ?>
    <div class="empty">
        <i data-lucide="file-text" style="width:36px;height:36px;color:var(--text-muted);margin-bottom:.5rem"></i>
        <div>No hay contratos todavía. Pulsa <strong>Nuevo contrato</strong> para crear el primero.</div>
    </div>
<?php else: ?>
    <table class="contratos">
        <thead><tr>
            <th style="padding-left:1.4rem">Contrato</th>
            <th>Tipo</th>
            <th>Contraparte</th>
            <th>Propuesta</th>
            <th>Estado</th>
            <th>Creado</th>
            <th style="text-align:right;padding-right:1.4rem">Acciones</th>
        </tr></thead>
        <tbody>
        <?php foreach ($contratos as $c):
            if ($c['destinatario_tipo'] === 'proveedor') {
                $contraparteName = $c['proveedor_nombre'] . ($c['proveedor_empresa'] ? ' · ' . $c['proveedor_empresa'] : '');
                $contraparteEmail = $c['proveedor_email'] ?? '';
            } elseif ($c['destinatario_tipo'] === 'cliente') {
                $contraparteName = ($c['cliente_nombre'] ?? '') . (!empty($c['cliente_empresa']) ? ' · ' . $c['cliente_empresa'] : '');
                if (trim($contraparteName) === '' || $contraparteName === ' · ') $contraparteName = $c['client_name'] ?: '—';
                $contraparteEmail = $c['cliente_email'] ?? '';
            } else {
                $contraparteName = $c['client_name'] ?: '—';
                $contraparteEmail = '';
            }
            $initial = mb_strtoupper(mb_substr($contraparteName, 0, 1));
        ?>
        <tr>
            <td style="padding-left:1.4rem"><a href="?contrato_id=<?=$c['id']?>" style="color:var(--text-primary);text-decoration:none;font-weight:600"><?=e($c['titulo'])?></a></td>
            <td><span class="tipo-chip"><?=e($c['plantilla_tipo'] ?? '—')?></span></td>
            <td>
                <div class="contraparte">
                    <div class="avatar"><?=e($initial)?></div>
                    <div class="meta">
                        <div class="name"><?=e($contraparteName)?></div>
                        <?php if ($contraparteEmail): ?><div class="email"><?=e($contraparteEmail)?></div><?php endif; ?>
                    </div>
                </div>
            </td>
            <td><?php if ($c['propuesta_slug']): ?><a href="/p/<?=e($c['propuesta_slug'])?>" target="_blank" style="color:var(--text-muted);text-decoration:none"><?=e($c['client_name'])?></a><?php else: ?><span style="color:var(--text-muted)">—</span><?php endif; ?></td>
            <td><span class="pill pill-<?=e($c['estado'])?>"><?=e(str_replace('_',' ',$c['estado']))?></span></td>
            <td style="color:var(--text-muted);font-size:.78rem"><?=fecha($c['created_at'])?></td>
            <td>
                <div class="actions">
                    <a class="icon-btn" href="?contrato_id=<?=$c['id']?>" title="Ver"><i data-lucide="eye" style="width:14px;height:14px"></i></a>
                    <?php if ($c['pdf_firmado_path']): ?>
                    <a class="icon-btn" href="?download_pdf=<?=$c['id']?>&kind=signed" target="_blank" title="Descargar PDF firmado"><i data-lucide="download" style="width:14px;height:14px;color:var(--mint)"></i></a>
                    <?php elseif ($c['pdf_sin_firmar_path']): ?>
                    <a class="icon-btn" href="?download_pdf=<?=$c['id']?>&kind=draft" target="_blank" title="Descargar borrador"><i data-lucide="file-down" style="width:14px;height:14px"></i></a>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>

<div class="card">
    <h2><i data-lucide="layout-template" style="width:16px;height:16px"></i> Plantillas disponibles</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem">
    <?php foreach ($plantillas as $p): ?>
        <div style="background:var(--bg-subtle);border:1px solid var(--border-base);padding:.85rem 1rem;border-radius:10px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.35rem">
                <span class="tipo-chip"><?=e($p['tipo'])?></span>
                <span style="color:var(--text-muted);font-size:.7rem"><?=e($p['destinatario'])?></span>
            </div>
            <div style="font-weight:600;font-size:.88rem;margin-bottom:.15rem"><?=e($p['nombre'])?></div>
            <div style="color:var(--text-muted);font-size:.7rem">
                <?php $vars = json_decode($p['variables_json'], true) ?: []; ?>
                <?= count($vars) ?> variables · <?=e(implode(' + ', json_decode($p['firmantes_json'], true) ?: []))?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
</div>

<?php else: // Detalle de contrato ?>

<?php if (!$contratoView): ?>
    <div class="card empty">Contrato no encontrado. <a href="admin_contratos.php" style="color:var(--mint)">Volver al listado</a></div>
<?php else: ?>

<div class="admin-main-header">
    <h1 class="admin-main-title">
        <i data-lucide="file-signature"></i>
        <?=e($contratoView['titulo'])?>
        <small>· #<?=$contratoView['id']?></small>
    </h1>
    <div class="admin-main-actions">
        <span class="pill pill-<?=e($contratoView['estado'])?>" style="font-size:.78rem;padding:.35rem .85rem"><?=e(str_replace('_',' ',$contratoView['estado']))?></span>
        <?php if ($contratoView['pdf_firmado_path']): ?>
        <a class="btn btn-primary" href="?download_pdf=<?=$contratoView['id']?>&kind=signed" target="_blank">
            <i data-lucide="download" style="width:14px;height:14px"></i> PDF firmado
        </a>
        <?php elseif ($contratoView['pdf_sin_firmar_path']): ?>
        <a class="btn" href="?download_pdf=<?=$contratoView['id']?>&kind=draft" target="_blank">
            <i data-lucide="file-down" style="width:14px;height:14px"></i> Borrador PDF
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="detail-grid">
    <div>
        <div class="card">
            <h2><i data-lucide="users" style="width:16px;height:16px"></i> Firmantes</h2>
            <?php foreach ($firmasView as $f):
                $signed = !empty($f['firmado_at']);
                $isTpPending = $f['rol'] === 'tp' && !$signed;
            ?>
            <div class="firma-row <?=$signed ? 'signed' : ''?>">
                <span class="rol-badge <?=$f['rol']==='tp' ? 'rol-tp' : ''?>"><?=e($f['rol'])?></span>
                <div style="flex:1">
                    <div class="name"><?=e($f['firmante_nombre'] ?? '—')?></div>
                    <div class="email"><?=e($f['firmante_email'] ?? '')?> <?php if ($f['firmante_documento']): ?>· <?=e($f['firmante_documento'])?><?php endif; ?></div>
                </div>
                <div class="status">
                    <?php if ($signed): ?>
                        ✓ firmado <?=fecha($f['firmado_at'])?>
                    <?php else: ?>
                        ⌛ pendiente
                    <?php endif; ?>
                </div>
                <?php if ($isTpPending): ?>
                <button class="btn btn-primary" onclick="openSignAsTp(<?=$contratoView['id']?>, '<?=e($contratoView['titulo'])?>')">
                    <i data-lucide="pen-tool" style="width:14px;height:14px"></i> Firmar como TP
                </button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <h2><i data-lucide="info" style="width:16px;height:16px"></i> Datos del contrato</h2>
            <div style="font-size:.85rem;line-height:1.7">
                <div><strong>Plantilla:</strong> <?=e($contratoView['plantilla_nombre'])?></div>
                <div><strong>Hash documento:</strong> <code style="background:var(--bg-subtle);padding:.15rem .35rem;border-radius:4px;font-size:.78rem"><?=e(contrato_hash_short($contratoView['hash_documento']))?></code></div>
                <?php if ($contratoView['hash_final']): ?><div><strong>Hash final:</strong> <code style="background:var(--bg-subtle);padding:.15rem .35rem;border-radius:4px;font-size:.78rem"><?=e(contrato_hash_short($contratoView['hash_final']))?></code></div><?php endif; ?>
                <div><strong>Creado:</strong> <?=fecha($contratoView['created_at'])?></div>
                <?php if ($contratoView['enviado_at']): ?><div><strong>Enviado:</strong> <?=fecha($contratoView['enviado_at'])?></div><?php endif; ?>
                <?php if ($contratoView['firmado_at']): ?><div><strong>Firmado:</strong> <?=fecha($contratoView['firmado_at'])?></div><?php endif; ?>
                <?php if ($contratoView['expira_at']): ?><div><strong>Expira:</strong> <?=fecha($contratoView['expira_at'])?></div><?php endif; ?>
            </div>
            <?php
            // Link público de firma (válido para cualquier destinatario)
            $signUrl = '/sign.php?token=' . urlencode($contratoView['signing_token'] ?? '');
            $destSlotEmail = '';
            foreach ($firmasView as $fv) {
                if ($fv['rol'] === $contratoView['destinatario_tipo']) {
                    $destSlotEmail = $fv['firmante_email'] ?? '';
                    break;
                }
            }
            ?>

            <?php if ($contratoView['estado'] === 'borrador'): ?>
            <div style="margin-top:1rem;background:var(--bg-subtle);padding:1rem;border-radius:10px">
                <div style="font-size:.78rem;color:var(--text-muted);margin-bottom:.55rem">Al enviar, se genera el PDF borrador y (si hay email) se notifica al firmante con el link de firma.</div>
                <div class="field" style="margin-bottom:.6rem">
                    <label style="font-size:.7rem">Email/s del firmante (destinatario <strong><?=e($contratoView['destinatario_tipo'])?>)</strong></label>
                    <input type="text" id="signerEmailInput" value="<?=e($destSlotEmail)?>" placeholder="persona@empresa.com, otro@empresa.com" style="width:100%;background:var(--bg-muted);border:1px solid var(--border-base);color:var(--text-primary);padding:.5rem .7rem;border-radius:6px;font-size:.85rem">
                    <div style="font-size:.68rem;color:var(--text-muted);margin-top:.3rem">Puedes poner varios separados por coma o punto y coma. Se enviará el mismo link a todos.</div>
                </div>
                <button class="btn btn-primary" onclick="sendToSigner(<?=$contratoView['id']?>)">
                    <i data-lucide="send" style="width:14px;height:14px"></i> Enviar al firmante
                </button>
            </div>
            <?php endif; ?>

            <?php if ($contratoView['estado'] !== 'borrador' && !empty($contratoView['signing_token'])): ?>
            <div style="margin-top:1rem;background:var(--bg-subtle);padding:1rem;border-radius:10px">
                <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);font-weight:600;margin-bottom:.45rem">Link público de firma</div>
                <div style="display:flex;gap:.4rem;align-items:center;margin-bottom:.6rem">
                    <input type="text" readonly value="<?=e($signUrl)?>" id="signUrlCopy" style="flex:1;background:var(--bg-muted);border:1px solid var(--border-base);color:var(--text-primary);padding:.5rem .7rem;border-radius:6px;font-size:.78rem;font-family:'JetBrains Mono',monospace">
                    <button class="btn" onclick="copySignUrl()"><i data-lucide="copy" style="width:14px;height:14px"></i></button>
                    <a class="btn" href="<?=e($signUrl)?>" target="_blank"><i data-lucide="external-link" style="width:14px;height:14px"></i></a>
                </div>
                <div style="display:flex;gap:.4rem;align-items:center">
                    <input type="text" id="resendEmailInput" value="<?=e($destSlotEmail)?>" placeholder="email1@x.com, email2@x.com" style="flex:1;background:var(--bg-muted);border:1px solid var(--border-base);color:var(--text-primary);padding:.5rem .7rem;border-radius:6px;font-size:.78rem">
                    <button class="btn" onclick="resendEmail(<?=$contratoView['id']?>)" title="Enviar email con link de firma">
                        <i data-lucide="mail" style="width:14px;height:14px"></i> Enviar email
                    </button>
                </div>
                <div style="font-size:.68rem;color:var(--text-muted);margin-top:.3rem">Separa varios emails con coma o punto y coma.</div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <div class="card">
            <h2><i data-lucide="activity" style="width:16px;height:16px"></i> Audit trail</h2>
            <div class="timeline">
                <?php if (empty($eventosView)): ?>
                <div style="color:var(--text-muted);font-size:.78rem">Sin eventos todavía</div>
                <?php else: foreach ($eventosView as $ev): ?>
                <div class="timeline-item">
                    <div class="timeline-time"><?=date('d/m H:i', strtotime($ev['created_at']))?></div>
                    <div>
                        <strong><?=e($ev['evento'])?></strong>
                        <?php if ($ev['actor']): ?><span style="color:var(--text-muted)"> · <?=e($ev['actor'])?></span><?php endif; ?>
                        <?php if ($ev['ip']): ?><div style="color:var(--text-muted);font-size:.7rem"><?=e($ev['ip'])?></div><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>
<?php endif; ?>

</main>
</div>

<!-- Modal: Nuevo contrato — tabs [Desde plantilla] / [Subir PDF] -->
<div class="modal-backdrop" id="createModal">
    <div class="modal">
        <div class="modal-head">
            <h2><i data-lucide="file-plus" style="width:18px;height:18px"></i> Nuevo contrato</h2>
            <button class="icon-btn" onclick="closeCreateModal()"><i data-lucide="x"></i></button>
        </div>

        <div style="display:flex;gap:.25rem;padding:0 1.5rem;border-bottom:1px solid var(--border-base)">
            <button type="button" class="tab active" id="tabPlantilla" onclick="switchCreateTab('plantilla')">
                <i data-lucide="layout-template" style="width:14px;height:14px;margin-right:.35rem"></i> Desde plantilla
            </button>
            <button type="button" class="tab" id="tabPdf" onclick="switchCreateTab('pdf')">
                <i data-lucide="upload" style="width:14px;height:14px;margin-right:.35rem"></i> Subir PDF directo
            </button>
        </div>

        <!-- FORM 1 · Desde plantilla -->
        <form id="createForm" onsubmit="return submitCreate(event)">
            <div class="modal-body">
                <div class="field">
                    <label>1. Plantilla</label>
                    <select name="plantilla_id" id="plantillaSelect" required onchange="onPlantillaChange()">
                        <option value="">— Elige una plantilla —</option>
                        <?php foreach ($plantillas as $p): ?>
                        <option value="<?=$p['id']?>" data-vars='<?=e($p['variables_json'])?>' data-firmantes='<?=e($p['firmantes_json'])?>' data-tipo="<?=e($p['tipo'])?>" data-destinatario="<?=e($p['destinatario'])?>">
                            [<?=e($p['tipo'])?>] <?=e($p['nombre'])?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>2. Título del contrato</label>
                    <input type="text" name="titulo" id="tituloInput" placeholder="Ej. Contrato subcontratación · Truman · Cardalis" required>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>3. Vinculado a propuesta (opcional)</label>
                        <select name="propuesta_id">
                            <option value="">— Sin propuesta —</option>
                            <?php foreach ($propuestasDisponibles as $p): ?>
                            <option value="<?=$p['id']?>"><?=e($p['client_name'])?> · <?=e($p['slug'])?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>4. Contraparte</label>
                        <select name="destinatario_id" id="destinatarioSelect">
                            <option value="">— Selecciona contraparte —</option>
                            <?php if (!empty($clientesDisponibles)): ?>
                            <optgroup label="Clientes">
                                <?php foreach ($clientesDisponibles as $cl): ?>
                                <option value="cli:<?=$cl['id']?>" data-prop="<?=$cl['propuesta_id']?>"><?=e($cl['nombre'])?><?php if (!empty($cl['empresa'])): ?> · <?=e($cl['empresa'])?><?php endif; ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endif; ?>
                            <optgroup label="Proveedores">
                                <?php foreach ($proveedoresDisponibles as $pv): ?>
                                <option value="prov:<?=$pv['id']?>" data-prop="<?=$pv['propuesta_id']?>"><?=e($pv['nombre'])?> · <?=e($pv['empresa'])?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                </div>

                <div class="field">
                    <label>5. Variables de la plantilla</label>
                    <div id="varsContainer" style="background:var(--bg-subtle);padding:1rem;border-radius:8px">
                        <div style="color:var(--text-muted);font-size:.8rem">Selecciona una plantilla para ver sus variables</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeCreateModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i data-lucide="check" style="width:14px;height:14px"></i> Crear desde plantilla</button>
            </div>
        </form>

        <!-- FORM 2 · Subir PDF directo -->
        <form id="createPdfForm" onsubmit="return submitCreatePdf(event)" enctype="multipart/form-data" style="display:none">
            <div class="modal-body">
                <div style="background:var(--bg-subtle);padding:.85rem 1rem;border-radius:8px;margin-bottom:1.2rem;font-size:.8rem;color:var(--text-secondary);line-height:1.55">
                    <strong style="color:var(--text-primary)">Contrato one-off.</strong> Sube un PDF ya redactado (por ejemplo un contrato que hayas redactado para un proyecto concreto). El sistema lo firma tal cual y le añade la hoja de audit trail al final. No reusable, no editable.
                </div>

                <div class="field">
                    <label>1. Archivo PDF <span style="color:#ff6b6b">*</span></label>
                    <input type="file" name="pdf" accept="application/pdf,.pdf" required>
                    <div style="color:var(--text-muted);font-size:.72rem;margin-top:.25rem">Máximo 20 MB. Solo PDF.</div>
                </div>

                <div class="field">
                    <label>2. Título del contrato <span style="color:#ff6b6b">*</span></label>
                    <input type="text" name="titulo" placeholder="Ej. Contrato mantenimiento · Dani · Cardalis" required>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>3. Tipo</label>
                        <select name="tipo">
                            <option value="custom">Personalizado</option>
                            <option value="nda">NDA</option>
                            <option value="msa">MSA</option>
                            <option value="sow">SOW</option>
                            <option value="dpa">DPA</option>
                            <option value="mantenimiento">Mantenimiento</option>
                            <option value="change_order">Change Order</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>4. Requiere OTP por email</label>
                        <select name="require_otp">
                            <option value="0">No</option>
                            <option value="1">Sí (refuerza &gt;3.000€)</option>
                        </select>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>5. Vinculado a propuesta (opcional)</label>
                        <select name="propuesta_id">
                            <option value="">— Sin propuesta —</option>
                            <?php foreach ($propuestasDisponibles as $p): ?>
                            <option value="<?=$p['id']?>"><?=e($p['client_name'])?> · <?=e($p['slug'])?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>6. Contraparte (quién firma) <span style="color:#ff6b6b">*</span></label>
                        <select name="destinatario_id" required>
                            <option value="">— Selecciona —</option>
                            <?php if (!empty($clientesDisponibles)): ?>
                            <optgroup label="Clientes">
                                <?php foreach ($clientesDisponibles as $cl): ?>
                                <option value="cli:<?=$cl['id']?>"><?=e($cl['nombre'])?><?php if (!empty($cl['empresa'])): ?> · <?=e($cl['empresa'])?><?php endif; ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endif; ?>
                            <optgroup label="Proveedores">
                                <?php foreach ($proveedoresDisponibles as $pv): ?>
                                <option value="prov:<?=$pv['id']?>"><?=e($pv['nombre'])?> · <?=e($pv['empresa'])?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                </div>

                <div class="field">
                    <label>7. Firmantes (orden)</label>
                    <div style="display:flex;gap:.75rem;background:var(--bg-subtle);padding:.85rem 1rem;border-radius:8px">
                        <label style="display:flex;align-items:center;gap:.4rem;color:var(--text-secondary);font-size:.85rem;font-weight:400;margin:0">
                            <input type="checkbox" name="firmante_contraparte" checked disabled style="width:auto"> Contraparte (1º)
                        </label>
                        <label style="display:flex;align-items:center;gap:.4rem;color:var(--text-secondary);font-size:.85rem;font-weight:400;margin:0">
                            <input type="checkbox" name="firmante_tp" checked style="width:auto"> Tres Puntos (2º)
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeCreateModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i data-lucide="upload" style="width:14px;height:14px"></i> Subir y crear</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Firmar como TP -->
<div class="modal-backdrop" id="signModal">
    <div class="modal">
        <div class="modal-head">
            <h2><i data-lucide="pen-tool" style="width:18px;height:18px"></i> Firmar como Tres Puntos</h2>
            <button class="icon-btn" onclick="closeSignModal()"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <div style="background:var(--bg-subtle);padding:1rem;border-radius:8px;margin-bottom:1rem;font-size:.85rem">
                <div><strong>Firmante:</strong> <?= e(TP_FIRMANTE_NOMBRE) ?></div>
                <div><strong>DNI:</strong> <?= e(TP_FIRMANTE_DNI) ?></div>
                <div><strong>Cargo:</strong> <?= e(TP_FIRMANTE_CARGO) ?></div>
                <div><strong>Empresa:</strong> <?= e(TP_RAZON_SOCIAL) ?></div>
            </div>

            <div class="field">
                <label>Dibuja tu firma</label>
                <canvas id="sigPad" width="640" height="220"></canvas>
                <div style="display:flex;gap:.5rem;margin-top:.5rem">
                    <button class="btn btn-ghost" type="button" onclick="clearSig()"><i data-lucide="eraser" style="width:14px;height:14px"></i> Limpiar</button>
                </div>
            </div>

            <div class="field">
                <label style="display:flex;align-items:flex-start;gap:.55rem;color:var(--text-secondary);font-weight:400;font-size:.78rem">
                    <input type="checkbox" id="consentCheck" style="margin-top:.2rem;width:auto">
                    <span id="consentLabel">He leído íntegramente el documento y acepto firmarlo electrónicamente conforme al Reglamento UE 910/2014 (eIDAS).</span>
                </label>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeSignModal()">Cancelar</button>
            <button type="button" class="btn btn-primary" id="signBtn" onclick="submitSignAsTp()" disabled><i data-lucide="check" style="width:14px;height:14px"></i> Firmar</button>
        </div>
    </div>
</div>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
lucide.createIcons();
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

// Modal create
function openCreateModal(){ document.getElementById('createModal').classList.add('open'); }
function closeCreateModal(){ document.getElementById('createModal').classList.remove('open'); }
function switchCreateTab(which){
    const tabPlant = document.getElementById('tabPlantilla');
    const tabPdf = document.getElementById('tabPdf');
    const formPlant = document.getElementById('createForm');
    const formPdf = document.getElementById('createPdfForm');
    if (which === 'pdf') {
        tabPlant.classList.remove('active'); tabPdf.classList.add('active');
        formPlant.style.display = 'none'; formPdf.style.display = '';
    } else {
        tabPlant.classList.add('active'); tabPdf.classList.remove('active');
        formPlant.style.display = ''; formPdf.style.display = 'none';
    }
}

async function submitCreatePdf(ev){
    ev.preventDefault();
    const form = document.getElementById('createPdfForm');
    const fd = new FormData(form);
    fd.append('action', 'create_from_pdf');
    fd.append('csrf_token', CSRF_TOKEN);
    const dest = fd.get('destinatario_id');
    if (dest && dest.startsWith('prov:')) {
        fd.set('destinatario_tipo', 'proveedor');
        fd.set('destinatario_id', dest.replace('prov:', ''));
    } else if (dest && dest.startsWith('cli:')) {
        fd.set('destinatario_tipo', 'cliente');
        fd.set('destinatario_id', dest.replace('cli:', ''));
    } else {
        fd.set('destinatario_tipo', 'cliente');
        fd.set('destinatario_id', '');
    }
    // Firmantes → array JSON (orden: contraparte primero, tp después)
    const firmantes = [];
    const contraparteTipo = fd.get('destinatario_tipo');
    firmantes.push(contraparteTipo);
    if (form.firmante_tp.checked) firmantes.push('tp');
    fd.append('firmantes', JSON.stringify(firmantes));

    const btn = form.querySelector('button[type=submit]');
    btn.disabled = true; btn.textContent = 'Subiendo…';
    const res = await fetch('admin_contratos.php', { method:'POST', body:fd });
    const data = await res.json();
    if (data.success) {
        location.href = 'admin_contratos.php?contrato_id=' + data.contrato_id;
    } else {
        alert('Error: ' + (data.error || 'desconocido'));
        btn.disabled = false; btn.innerHTML = '<i data-lucide="upload" style="width:14px;height:14px"></i> Subir y crear';
    }
    return false;
}

function onPlantillaChange(){
    const sel = document.getElementById('plantillaSelect');
    const opt = sel.options[sel.selectedIndex];
    const cont = document.getElementById('varsContainer');
    if (!opt.value) { cont.innerHTML='<div style="color:var(--text-muted);font-size:.8rem">Selecciona una plantilla para ver sus variables</div>'; return; }
    const vars = JSON.parse(opt.dataset.vars || '[]');
    let html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:.85rem">';
    vars.forEach(v => {
        const isLong = v.type === 'textarea';
        const inp = isLong
            ? `<textarea name="var_${v.name}">${v.default || ''}</textarea>`
            : `<input type="${v.type === 'number' ? 'number' : (v.type === 'date' ? 'date' : 'text')}" name="var_${v.name}" value="${v.default || ''}">`;
        html += `<div class="field" style="grid-column:${isLong ? 'span 2' : 'auto'};margin:0">
            <label style="font-size:.72rem">${v.label}</label>
            ${inp}
        </div>`;
    });
    html += '</div>';
    cont.innerHTML = html;
}

async function submitCreate(ev){
    ev.preventDefault();
    const form = document.getElementById('createForm');
    const fd = new FormData(form);
    const body = new FormData();
    body.append('action', 'create_from_plantilla');
    body.append('csrf_token', CSRF_TOKEN);
    body.append('plantilla_id', fd.get('plantilla_id'));
    body.append('titulo', fd.get('titulo'));
    if (fd.get('propuesta_id')) body.append('propuesta_id', fd.get('propuesta_id'));
    const dest = fd.get('destinatario_id');
    if (dest && dest.startsWith('prov:')) {
        body.append('destinatario_tipo', 'proveedor');
        body.append('destinatario_id', dest.replace('prov:', ''));
    } else if (dest && dest.startsWith('cli:')) {
        body.append('destinatario_tipo', 'cliente');
        body.append('destinatario_id', dest.replace('cli:', ''));
    }
    const datos = {};
    for (const [k,v] of fd.entries()) {
        if (k.startsWith('var_')) datos[k.substring(4)] = v;
    }
    body.append('datos', JSON.stringify(datos));
    const res = await fetch('admin_contratos.php', { method:'POST', body });
    const data = await res.json();
    if (data.success) {
        location.href = 'admin_contratos.php?contrato_id=' + data.contrato_id;
    } else {
        alert('Error: ' + (data.error || 'desconocido'));
    }
    return false;
}

async function sendToSigner(id){
    const emailInput = document.getElementById('signerEmailInput');
    const email = emailInput ? emailInput.value.trim() : '';
    if (!email) {
        if (!confirm('No has puesto email del firmante. Se enviará sin email (tendrás que pasarle el link a mano). ¿Continuar?')) return;
    }
    const fd = new FormData();
    fd.append('action', 'send_to_signer');
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('contrato_id', id);
    if (email) fd.append('force_email', email);
    const res = await fetch('admin_contratos.php', { method:'POST', body:fd });
    const data = await res.json();
    if (data.success) {
        let msg = 'Contrato enviado.\n\n';
        const sent = Array.isArray(data.email_to) ? data.email_to : (data.email_to ? [data.email_to] : []);
        if (data.email_sent && sent.length) msg += '✓ Email enviado a: ' + sent.join(', ') + '\n';
        else if (sent.length) msg += '⚠ Email no se pudo enviar (revisa Resend).\n';
        else msg += '⚠ ' + (data.email_error || 'Sin email configurado') + '\n';
        if (Array.isArray(data.email_invalid) && data.email_invalid.length) {
            msg += '⚠ Direcciones inválidas ignoradas: ' + data.email_invalid.join(', ') + '\n';
        }
        msg += '\nLink de firma:\n' + data.sign_url;
        alert(msg);
        location.reload();
    } else alert('Error: ' + (data.error || 'desconocido'));
}

function copySignUrl(){
    const inp = document.getElementById('signUrlCopy');
    inp.select(); inp.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(inp.value);
    const btn = event.target.closest('button');
    if (btn) { const orig = btn.innerHTML; btn.innerHTML = '✓'; setTimeout(()=> btn.innerHTML = orig, 1200); }
}

async function resendEmail(id){
    const email = document.getElementById('resendEmailInput').value.trim();
    if (!email) { alert('Escribe el email primero'); return; }
    const fd = new FormData();
    fd.append('action', 'resend_email');
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('contrato_id', id);
    fd.append('email', email);
    const res = await fetch('admin_contratos.php', { method:'POST', body:fd });
    const data = await res.json();
    if (data.success) {
        const to = Array.isArray(data.email_to) ? data.email_to.join(', ') : email;
        let msg = '✓ Email enviado a ' + to;
        if (Array.isArray(data.email_invalid) && data.email_invalid.length) msg += '\n⚠ Inválidos ignorados: ' + data.email_invalid.join(', ');
        alert(msg);
    } else alert('Error: ' + (data.error || 'No se pudo enviar. Revisa Resend.'));
}

// Sign modal
let sigCanvas, sigCtx, sigDrawing = false, sigStartTs = 0, sigContratoId = null;
function openSignAsTp(id, titulo){
    sigContratoId = id;
    sigStartTs = Date.now();
    document.getElementById('consentLabel').textContent = `He leído íntegramente el documento "${titulo}" y manifiesto mi conformidad con todas sus cláusulas. Acepto expresamente firmarlo mediante firma electrónica conforme al Reglamento (UE) 910/2014 (eIDAS).`;
    document.getElementById('signModal').classList.add('open');
    setTimeout(initSigPad, 50);
}
function closeSignModal(){
    document.getElementById('signModal').classList.remove('open');
    sigContratoId = null;
}
function initSigPad(){
    sigCanvas = document.getElementById('sigPad');
    sigCtx = sigCanvas.getContext('2d');
    sigCtx.strokeStyle = '#0e0e0e';
    sigCtx.lineWidth = 2.2;
    sigCtx.lineCap = 'round';
    sigCtx.lineJoin = 'round';
    sigCanvas.onpointerdown = e => { sigDrawing = true; const r = sigCanvas.getBoundingClientRect(); sigCtx.beginPath(); sigCtx.moveTo(e.clientX - r.left, e.clientY - r.top); };
    sigCanvas.onpointermove = e => { if(!sigDrawing) return; const r = sigCanvas.getBoundingClientRect(); sigCtx.lineTo(e.clientX - r.left, e.clientY - r.top); sigCtx.stroke(); checkSignReady(); };
    sigCanvas.onpointerup = sigCanvas.onpointerleave = () => sigDrawing = false;
    document.getElementById('consentCheck').onchange = checkSignReady;
}
function clearSig(){ sigCtx.clearRect(0,0, sigCanvas.width, sigCanvas.height); checkSignReady(); }
function checkSignReady(){
    const consent = document.getElementById('consentCheck').checked;
    const empty = isCanvasEmpty();
    document.getElementById('signBtn').disabled = empty || !consent;
}
function isCanvasEmpty(){
    const data = sigCtx.getImageData(0,0,sigCanvas.width, sigCanvas.height).data;
    for (let i = 3; i < data.length; i += 4) if (data[i] !== 0) return false;
    return true;
}
async function submitSignAsTp(){
    const trazo = sigCanvas.toDataURL('image/png');
    const fd = new FormData();
    fd.append('action', 'sign_as_tp');
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('contrato_id', sigContratoId);
    fd.append('trazo_base64', trazo);
    fd.append('consent', '1');
    fd.append('signing_duration_ms', String(Date.now() - sigStartTs));
    fd.append('client_timestamp', new Date().toISOString());
    fd.append('scroll_depth_pct', '100');
    const res = await fetch('admin_contratos.php', { method:'POST', body:fd });
    const data = await res.json();
    if (data.success) {
        if (data.completed) alert('Firma completa. PDF final generado.');
        location.reload();
    } else {
        alert('Error: ' + (data.error || 'desconocido'));
    }
}
</script>
</body>
</html>
