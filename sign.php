<?php
/**
 * Firma pública por token — accesible para cualquier firmante (cliente o proveedor).
 *
 * URL: /sign.php?token=XXXX
 *
 * Diferencia con provider_contrato.php (que usa el token del proveedor en /s/):
 *   - Este endpoint usa contratos.signing_token (único por contrato).
 *   - No requiere sesión previa. El token es la autorización.
 *   - Se identifica al firmante por el contrato (busca el slot pendiente del rol correspondiente).
 *   - Soporta cliente + proveedor + cualquier destinatario.
 */

require __DIR__ . '/config.php';
require __DIR__ . '/api/contratos_lib.php';

$token = trim($_GET['token'] ?? '');
if (!$token || !preg_match('/^[a-f0-9]{32}$/', $token)) {
    http_response_code(400); echo 'Token inválido'; exit;
}

$pdo = getDBConnection();
$ctr = $pdo->prepare("
    SELECT c.*, p.html_content, p.tipo AS plantilla_tipo, p.firmantes_json, p.require_otp AS plant_require_otp
    FROM contratos c
    LEFT JOIN contratos_plantillas p ON p.id = c.plantilla_id
    WHERE c.signing_token = ?
");
$ctr->execute([$token]);
$contrato = $ctr->fetch(PDO::FETCH_ASSOC);
if (!$contrato) { http_response_code(404); echo 'Contrato no encontrado'; exit; }

$datosMeta = json_decode($contrato['datos_json'] ?: '{}', true) ?: [];
$isPdfDirect = empty($contrato['plantilla_id']);
$contrato['require_otp'] = $isPdfDirect ? (int)($datosMeta['require_otp'] ?? 0) : (int)($contrato['plant_require_otp'] ?? 0);

// Slot de firma destinatario (el primer rol pendiente distinto de 'tp')
$rolDestinatario = $contrato['destinatario_tipo']; // 'cliente' | 'proveedor'
$slotStmt = $pdo->prepare("SELECT * FROM contratos_firmas WHERE contrato_id = ? AND rol = ? LIMIT 1");
$slotStmt->execute([$contrato['id'], $rolDestinatario]);
$firmaSlot = $slotStmt->fetch(PDO::FETCH_ASSOC);

$alreadySigned = $firmaSlot && !empty($firmaSlot['firmado_at']);
$wasFullySigned = $contrato['estado'] === 'firmado';

// Marcar como visto si aplica
if ($contrato['estado'] === 'enviado') {
    $pdo->prepare("UPDATE contratos SET estado = 'visto' WHERE id = ?")->execute([$contrato['id']]);
    contrato_log_evento($pdo, $contrato['id'], 'visto', $firmaSlot['firmante_email'] ?? 'desconocido');
}

// Descarga PDF final si está firmado
if (isset($_GET['download']) && $wasFullySigned && $contrato['pdf_firmado_path']) {
    $abs = __DIR__ . '/' . $contrato['pdf_firmado_path'];
    if (file_exists($abs)) {
        contrato_log_evento($pdo, $contrato['id'], 'descargado_firmante', $firmaSlot['firmante_email'] ?? '—');
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="contrato-' . $contrato['id'] . '-firmado.pdf"');
        readfile($abs);
        exit;
    }
}

// Servir PDF directo en iframe
if (isset($_GET['view_pdf']) && $isPdfDirect && $contrato['pdf_sin_firmar_path']) {
    $abs = __DIR__ . '/' . $contrato['pdf_sin_firmar_path'];
    if (file_exists($abs)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="contrato-' . $contrato['id'] . '.pdf"');
        readfile($abs);
        exit;
    }
    http_response_code(404); exit;
}

// ====================================================================
//   POST: OTP / firmar
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    if ($action === 'request_otp') {
        if (!$firmaSlot) { echo json_encode(['success' => false, 'error' => 'No slot']); exit; }
        $email = trim($_POST['email'] ?? $firmaSlot['firmante_email'] ?? '');
        $nombre = trim($_POST['nombre'] ?? $firmaSlot['firmante_nombre'] ?? '');
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success' => false, 'error' => 'Email inválido']); exit; }
        $code = contrato_generate_otp();
        contrato_store_otp($pdo, $firmaSlot['id'], $code);
        $sent = contrato_send_otp_email($email, $nombre ?: 'Firmante', $code, $contrato['titulo']);
        contrato_log_evento($pdo, $contrato['id'], 'otp_enviado', $email);
        echo json_encode(['success' => $sent, 'email' => $email]);
        exit;
    }

    if ($action === 'sign') {
        if (!$firmaSlot) { echo json_encode(['success' => false, 'error' => 'No slot']); exit; }
        if (!empty($firmaSlot['firmado_at'])) { echo json_encode(['success' => false, 'error' => 'Ya firmado']); exit; }

        $trazo = $_POST['trazo_base64'] ?? '';
        $consent = !empty($_POST['consent']);
        $nombreFirma = trim($_POST['firmante_nombre'] ?? '');
        $emailFirma = trim($_POST['firmante_email'] ?? '');
        $documentoFirma = trim($_POST['firmante_documento'] ?? '');
        $cargoFirma = trim($_POST['firmante_cargo'] ?? '');
        $otpInput = trim($_POST['otp_code'] ?? '');

        if (!$trazo || !$consent) { echo json_encode(['success' => false, 'error' => 'Faltan datos']); exit; }
        if (!$nombreFirma || !$emailFirma || !filter_var($emailFirma, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Nombre y email válidos obligatorios']); exit;
        }

        if ($contrato['require_otp']) {
            if (!contrato_verify_otp($pdo, $firmaSlot['id'], $otpInput)) {
                echo json_encode(['success' => false, 'error' => 'Código OTP incorrecto o expirado']); exit;
            }
        }

        $consentText = contrato_consent_text($contrato['titulo']);
        $ip = contrato_client_ip();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $now = gmdate('Y-m-d H:i:s');
        $hashFirma = hash('sha256', $contrato['hash_documento'] . $emailFirma . $now . $ip . random_bytes(16));

        $pdo->prepare("UPDATE contratos_firmas SET
            firmante_nombre = ?, firmante_email = ?, firmante_documento = ?, firmante_cargo = ?,
            firma_trazo_base64 = ?, firma_hash = ?, ip = ?, geoip_country = ?, user_agent = ?,
            consent_texto = ?, consent_aceptado = 1, signing_method = ?,
            signing_duration_ms = ?, scroll_depth_pct = ?,
            server_timestamp_utc = ?, client_timestamp = ?,
            firmado_at = datetime('now')
            WHERE id = ?")
            ->execute([
                $nombreFirma, $emailFirma, $documentoFirma ?: null, $cargoFirma ?: null,
                $trazo, $hashFirma, $ip, contrato_resolve_country($ip), $ua,
                $consentText,
                $contrato['require_otp'] ? 'trazo+otp' : 'trazo',
                (int)($_POST['signing_duration_ms'] ?? 0),
                (int)($_POST['scroll_depth_pct'] ?? 100),
                $now, $_POST['client_timestamp'] ?? null,
                $firmaSlot['id'],
            ]);
        contrato_log_evento($pdo, $contrato['id'], 'firmado_' . $rolDestinatario, $emailFirma);

        // Todas las firmas completas → PDF final
        $pendientes = $pdo->prepare("SELECT COUNT(*) FROM contratos_firmas WHERE contrato_id = ? AND firmado_at IS NULL");
        $pendientes->execute([$contrato['id']]);
        $completed = (int)$pendientes->fetchColumn() === 0;
        if ($completed) {
            _sign_generar_pdf_final_inline($pdo, $contrato['id']);
        } else {
            $pdo->prepare("UPDATE contratos SET estado = 'firmado_parcial' WHERE id = ?")->execute([$contrato['id']]);
        }

        echo json_encode(['success' => true, 'completed' => $completed]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Acción desconocida']);
    exit;
}

function _sign_generar_pdf_final_inline(PDO $pdo, int $contratoId): void {
    $row = $pdo->prepare("SELECT c.*, p.html_content, p.tipo AS plantilla_tipo FROM contratos c LEFT JOIN contratos_plantillas p ON p.id = c.plantilla_id WHERE c.id = ?");
    $row->execute([$contratoId]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    $firmas = $pdo->prepare("SELECT * FROM contratos_firmas WHERE contrato_id = ? ORDER BY orden ASC");
    $firmas->execute([$contratoId]);
    $firmasArr = $firmas->fetchAll(PDO::FETCH_ASSOC);
    $tsa = contrato_request_tsa_timestamp($r['hash_documento']);
    $dir = __DIR__ . '/uploads/contratos/' . $contratoId;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $finalPath = $dir . '/v_final.pdf';
    $meta = [
        'titulo' => $r['titulo'],
        'tipo' => $r['plantilla_tipo'] ?? (json_decode($r['datos_json'] ?: '{}', true)['tipo'] ?? 'custom'),
        'hash_documento' => $r['hash_documento'],
        'tsa_timestamp' => $tsa,
    ];
    if (empty($r['plantilla_id']) && !empty($r['pdf_sin_firmar_path'])) {
        $basePath = __DIR__ . '/' . $r['pdf_sin_firmar_path'];
        contrato_stamp_pdf_with_audit($basePath, $firmasArr, $meta, $finalPath);
    } else {
        $datos = json_decode($r['datos_json'], true) ?: [];
        $html = contrato_render_template($r['html_content'], $datos);
        contrato_generate_pdf($html, $firmasArr, $meta, $finalPath);
    }
    $rel = 'uploads/contratos/' . $contratoId . '/v_final.pdf';
    $hashFinal = contrato_hash_file($finalPath);
    $pdo->prepare("UPDATE contratos SET pdf_firmado_path = ?, hash_final = ?, estado = 'firmado', firmado_at = datetime('now') WHERE id = ?")
        ->execute([$rel, $hashFinal, $contratoId]);
    if ($tsa) $pdo->prepare("UPDATE contratos_firmas SET tsa_timestamp = ? WHERE contrato_id = ?")->execute([$tsa, $contratoId]);
    contrato_log_evento($pdo, $contratoId, 'pdf_final_generado', 'sistema');
}

// ====================================================================
//   GET: render UI
// ====================================================================
$datos = json_decode($contrato['datos_json'], true) ?: [];
$contractHtml = $isPdfDirect ? '' : contrato_render_template($contrato['html_content'], $datos);
$pdfViewUrl = $isPdfDirect ? ('?token=' . urlencode($token) . '&view_pdf=1') : null;
$consentTextDefault = contrato_consent_text($contrato['titulo']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($contrato['titulo'], ENT_QUOTES, 'UTF-8') ?> · Tres Puntos</title>
<style>
:root { --mint:#5dffbf; --bg-base:#0e0e0e; --bg-surface:#141414; --bg-subtle:#191919; --bg-muted:#1f1f1f; --text-primary:#f5f5f5; --text-secondary:#b3b3b3; --text-muted:#8a8a8a; --border-base:#1f1f1f; }
* { box-sizing:border-box; }
body { margin:0; background:var(--bg-base); color:var(--text-primary); font-family:'Inter',system-ui,sans-serif; line-height:1.6; }
.wrap { max-width:920px; margin:0 auto; padding:2rem 1.5rem 4rem; }
.brand { font-weight:800; letter-spacing:.2em; font-size:.78rem; color:var(--text-muted); margin-bottom:.5rem; }
.hero { background:var(--bg-surface); border:1px solid var(--border-base); border-radius:16px; padding:2rem; margin-bottom:1.5rem; }
.hero h1 { font-size:1.6rem; margin:.25rem 0 .25rem; font-weight:700; }
.hero .sub { color:var(--mint); font-weight:600; margin-bottom:1rem; font-size:.95rem; }
.hero .meta { color:var(--text-muted); font-size:.85rem; }
.callout-info { background:rgba(93,255,191,.06); border-left:3px solid var(--mint); padding:1rem 1.2rem; border-radius:8px; margin-top:1.2rem; font-size:.88rem; color:var(--text-secondary); }
.contract-doc { background:#fff; color:#1a1a1a; padding:3rem 3.5rem; border-radius:14px; box-shadow:0 4px 20px rgba(0,0,0,.4); font-family:Georgia,serif; font-size:11.5pt; line-height:1.55; margin-bottom:1.5rem; }
.contract-doc .tp-cover h1, .contract-doc .tp-section h2 { font-family:'Plus Jakarta Sans',sans-serif; color:#0e0e0e; font-weight:800; }
.contract-doc .tp-cover h1 { font-size:1.6rem; margin:0 0 .4rem; }
.contract-doc .tp-cover .subtitle { color:#0FA36C; font-weight:700; font-size:1rem; margin-bottom:1rem; }
.contract-doc .tp-cover .brand { letter-spacing:.2em; color:#0FA36C; font-weight:800; font-size:.85rem; margin-bottom:.5rem; }
.contract-doc .tp-cover .rule { border:0; border-top:2px solid #0FA36C; width:80px; margin:.6rem 0 1rem; }
.contract-doc .tp-section { margin-top:2.5rem; }
.contract-doc .tp-section h2 { font-size:1.25rem; margin-bottom:.6rem; }
.contract-doc .tp-section h3 { font-size:1.05rem; margin:1.2rem 0 .4rem; color:#0e0e0e; font-weight:700; }
.contract-doc .tp-section p { margin:.4rem 0; }
.contract-doc table.tp-table { width:100%; border-collapse:collapse; margin:.6rem 0; font-size:10.5pt; }
.contract-doc table.tp-table th { background:#141414; color:#fff; padding:.5rem; text-align:left; }
.contract-doc table.tp-table td { padding:.5rem; border:1px solid #d5d5d5; }
.contract-doc .tp-callout { background:#F7F6F3; border-left:3px solid #0FA36C; padding:.7rem 1rem; border-radius:6px; margin:1rem 0; font-size:.9rem; }
.scroll-progress { position:sticky; top:0; height:3px; background:var(--bg-muted); margin:0 0 1rem; border-radius:99px; overflow:hidden; z-index:5; }
.scroll-progress div { height:100%; background:var(--mint); width:0%; transition:width .15s linear; }
.doc-end-sentinel { display:block; height:1px; margin-top:2rem; }
.sign-card { background:var(--bg-surface); border:1px solid var(--border-base); border-radius:14px; padding:2rem; }
.sign-card h2 { margin:0 0 .25rem; font-size:1.2rem; }
.sign-card .sub { color:var(--text-muted); font-size:.85rem; margin-bottom:1.5rem; }
.field { margin-bottom:1.1rem; }
.field label { display:block; color:var(--text-muted); font-size:.78rem; margin-bottom:.35rem; font-weight:600; }
.field input, .field select { width:100%; background:var(--bg-subtle); color:var(--text-primary); border:1px solid var(--border-base); padding:.7rem .85rem; border-radius:8px; font-size:.95rem; font-family:inherit; }
.field input:focus { outline:none; border-color:var(--mint); }
.row2 { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
@media (max-width:640px){ .row2 { grid-template-columns:1fr; } }
.consent { display:flex; align-items:flex-start; gap:.6rem; background:var(--bg-subtle); padding:1rem; border-radius:8px; font-size:.82rem; color:var(--text-secondary); line-height:1.5; }
.consent input { margin-top:.2rem; width:auto; }
.btn { background:var(--bg-muted); color:var(--text-primary); border:1px solid var(--border-base); padding:.85rem 1.2rem; border-radius:10px; cursor:pointer; font-size:.92rem; font-weight:600; display:inline-flex; align-items:center; gap:.5rem; text-decoration:none; }
.btn-primary { background:var(--mint); color:#000; border-color:var(--mint); }
.btn-primary:hover:not(:disabled) { background:#49e6a8; }
.btn:disabled { opacity:.4; cursor:not-allowed; }
.sign-pad-wrap { background:#fff; border-radius:10px; padding:.5rem; margin:.5rem 0; }
canvas#sigPad { display:block; width:100%; height:220px; touch-action:none; cursor:crosshair; border-radius:6px; user-select:none; }
.sigtools { display:flex; gap:.5rem; margin-top:.4rem; }
.alert { background:rgba(93,255,191,.08); border-left:3px solid var(--mint); padding:1rem 1.2rem; border-radius:8px; margin-bottom:1rem; color:var(--text-secondary); font-size:.85rem; }
.alert-ok { color:var(--mint); }
.alert-warn { background:rgba(255,200,80,.08); border-left-color:#ffc850; color:#ffd86a; }
.legal-clausula { font-size:.78rem; color:var(--text-muted); margin-top:1.2rem; line-height:1.5; }
</style>
</head>
<body>
<div class="wrap">
<div class="brand">TRES PUNTOS</div>

<?php if ($wasFullySigned): ?>
<div class="hero">
    <div class="alert alert-ok"><strong>✓ Contrato firmado por todas las partes.</strong> Ya puedes descargar el PDF con el certificado de firma electrónica adjunto.</div>
    <h1><?= htmlspecialchars($contrato['titulo'], ENT_QUOTES, 'UTF-8') ?></h1>
    <div class="meta">Firmado el <b><?= date('d/m/Y', strtotime($contrato['firmado_at'])) ?></b></div>
    <div style="margin-top:1.5rem"><a class="btn btn-primary" href="?token=<?= urlencode($token) ?>&download=1">⬇ Descargar PDF firmado</a></div>
</div>

<?php elseif ($alreadySigned): ?>
<div class="hero">
    <div class="alert alert-ok" style="background:rgba(93,255,191,.1)"><strong>✓ Tu firma se registró correctamente.</strong><br>Cuando Tres Puntos contra-firme, recibirás el PDF final.</div>
    <h1><?= htmlspecialchars($contrato['titulo'], ENT_QUOTES, 'UTF-8') ?></h1>
    <div class="meta" style="margin-top:1rem">Firmado el <?= date('d/m/Y H:i', strtotime($firmaSlot['firmado_at'])) ?> · IP <?= htmlspecialchars($firmaSlot['ip'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
</div>

<?php else: ?>
<div class="hero">
    <h1><?= htmlspecialchars($contrato['titulo'], ENT_QUOTES, 'UTF-8') ?></h1>
    <div class="sub">Pendiente de tu firma · <?= htmlspecialchars(ucfirst($rolDestinatario), ENT_QUOTES, 'UTF-8') ?></div>
    <div class="meta">Lee el documento y firma al final. Capturamos nombre, email, IP, navegador y timestamp como prueba técnica (eIDAS art. 25).</div>
    <div class="callout-info">Este documento se firma electrónicamente conforme al <strong>Reglamento (UE) 910/2014 (eIDAS)</strong>. Validez jurídica entre las partes equiparable a la firma manuscrita.</div>
</div>

<div class="scroll-progress"><div id="scrollBar"></div></div>

<?php if ($isPdfDirect): ?>
<div class="contract-doc" id="contractDoc" style="padding:0;background:#1f1f1f">
    <iframe src="<?= htmlspecialchars($pdfViewUrl, ENT_QUOTES, 'UTF-8') ?>" style="width:100%;height:720px;border:0;border-radius:14px;background:#fff" onload="onPdfLoaded()"></iframe>
    <div class="doc-end-sentinel" id="docEndSentinel"></div>
</div>
<div style="color:var(--text-muted);font-size:.78rem;margin:-.8rem 0 1.5rem">Scrollea dentro del PDF para leerlo. La firma se activa automáticamente.</div>
<?php else: ?>
<div class="contract-doc" id="contractDoc">
    <?= $contractHtml ?>
    <div class="doc-end-sentinel" id="docEndSentinel"></div>
</div>
<?php endif; ?>

<div class="sign-card">
    <h2>Firma del contrato</h2>
    <div class="sub">Firmas como <strong><?= htmlspecialchars($rolDestinatario === 'cliente' ? ($firmaSlot['firmante_nombre'] ?? 'Cliente') : ($firmaSlot['firmante_empresa'] ?? $firmaSlot['firmante_nombre'] ?? 'Proveedor'), ENT_QUOTES, 'UTF-8') ?></strong></div>

    <div class="row2">
        <div class="field">
            <label>Nombre completo del firmante</label>
            <input type="text" id="fNombre" value="<?= htmlspecialchars($firmaSlot['firmante_nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
        </div>
        <div class="field">
            <label>Email</label>
            <input type="email" id="fEmail" value="<?= htmlspecialchars($firmaSlot['firmante_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
        </div>
    </div>
    <div class="row2">
        <div class="field">
            <label>DNI / NIE / Documento de identidad</label>
            <input type="text" id="fDocumento" placeholder="Ej. 12345678A" required>
        </div>
        <div class="field">
            <label>Cargo / posición</label>
            <input type="text" id="fCargo" placeholder="Ej. CEO, Apoderado">
        </div>
    </div>

    <?php if ($contrato['require_otp']): ?>
    <div class="field">
        <label>Verificación por email (OTP)</label>
        <div style="display:flex;gap:.5rem">
            <input type="text" id="otpInput" placeholder="Código 6 dígitos" maxlength="6" inputmode="numeric" disabled>
            <button class="btn" type="button" id="otpBtn" onclick="requestOtp()">Enviarme código</button>
        </div>
    </div>
    <?php endif; ?>

    <div class="field">
        <label>Tu firma manuscrita</label>
        <div class="sign-pad-wrap"><canvas id="sigPad"></canvas></div>
        <div class="sigtools"><button class="btn" type="button" onclick="clearSig()" style="font-size:.78rem;padding:.5rem .85rem">Limpiar firma</button></div>
    </div>

    <div class="consent">
        <input type="checkbox" id="consentCheck" disabled>
        <label for="consentCheck"><?= htmlspecialchars($consentTextDefault, ENT_QUOTES, 'UTF-8') ?></label>
    </div>

    <div id="readWarning" class="alert alert-warn" style="margin-top:1rem">Lee el documento hasta el final para activar la firma.</div>

    <div style="margin-top:1.5rem;display:flex;justify-content:flex-end">
        <button class="btn btn-primary" id="signBtn" disabled onclick="submitSign()">✍ Firmar contrato</button>
    </div>
    <div class="legal-clausula">Conforme al art. 1262 CC y Reglamento (UE) 910/2014 (eIDAS), la firma electrónica de este documento tiene plena validez jurídica equiparable a la manuscrita.</div>
</div>

<?php endif; ?>
</div>

<script>
const TOKEN = <?= json_encode($token) ?>;
const REQUIRE_OTP = <?= $contrato['require_otp'] ? 'true' : 'false' ?>;
const SIGN_START = Date.now();
let SCROLL_OK = false;

const docEl = document.getElementById('contractDoc');
const sentinel = document.getElementById('docEndSentinel');
const bar = document.getElementById('scrollBar');
const warn = document.getElementById('readWarning');

function unlockSignature(){
    if (SCROLL_OK) return;
    SCROLL_OK = true;
    const c = document.getElementById('consentCheck'); if (c) c.disabled = false;
    if (warn) warn.style.display = 'none';
    if (bar) bar.style.width = '100%';
    checkReady();
}
function updateProgress(){
    if (!docEl || !bar) return;
    const rect = docEl.getBoundingClientRect();
    const docTop = window.scrollY + rect.top;
    const docHeight = docEl.scrollHeight;
    const viewed = Math.max(0, Math.min(docHeight, window.scrollY + window.innerHeight - docTop));
    const pct = Math.min(100, (viewed / docHeight) * 100);
    bar.style.width = pct + '%';
    if (pct >= 95) unlockSignature();
}
window.addEventListener('scroll', updateProgress, { passive:true });
window.addEventListener('resize', updateProgress);
updateProgress();
if (sentinel && 'IntersectionObserver' in window) {
    new IntersectionObserver(es => { for (const e of es) if (e.isIntersecting) unlockSignature(); }, { rootMargin:'0px 0px -20% 0px' }).observe(sentinel);
}
function onPdfLoaded(){ setTimeout(unlockSignature, 5000); }

// Canvas firma
const cv = document.getElementById('sigPad');
let ctx, drawing = false, lastPt = null, cssW = 0, cssH = 0;
function initSig(){
    if (!cv) return;
    const rect = cv.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    cssW = rect.width; cssH = rect.height;
    cv.width = cssW * dpr; cv.height = cssH * dpr;
    ctx = cv.getContext('2d');
    ctx.scale(dpr, dpr);
    ctx.strokeStyle = '#0e0e0e'; ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round';

    function pt(e){ const r = cv.getBoundingClientRect(); return { x:e.clientX-r.left, y:e.clientY-r.top }; }
    cv.addEventListener('pointerdown', e => { e.preventDefault(); drawing = true; lastPt = pt(e); cv.setPointerCapture(e.pointerId); ctx.beginPath(); ctx.arc(lastPt.x, lastPt.y, 1.2, 0, Math.PI*2); ctx.fillStyle='#0e0e0e'; ctx.fill(); });
    cv.addEventListener('pointermove', e => { if(!drawing) return; e.preventDefault(); const p = pt(e); const mid = { x:(lastPt.x+p.x)/2, y:(lastPt.y+p.y)/2 }; ctx.beginPath(); ctx.moveTo(lastPt.x, lastPt.y); ctx.quadraticCurveTo(lastPt.x, lastPt.y, mid.x, mid.y); ctx.stroke(); lastPt = p; checkReady(); });
    const stop = e => { if(drawing){ drawing=false; lastPt=null; try{cv.releasePointerCapture(e.pointerId);}catch(_){} } };
    cv.addEventListener('pointerup', stop); cv.addEventListener('pointerleave', stop); cv.addEventListener('pointercancel', stop);

    const cc = document.getElementById('consentCheck'); if (cc) cc.onchange = checkReady;
    ['fNombre','fEmail','fDocumento','otpInput'].forEach(id => { const el = document.getElementById(id); if(el) el.oninput = checkReady; });
}
function clearSig(){ if(ctx) ctx.clearRect(0,0,cssW,cssH); checkReady(); }
function isCanvasEmpty(){ if(!ctx) return true; const d = ctx.getImageData(0,0,cv.width,cv.height).data; for(let i=3;i<d.length;i+=4) if(d[i]!==0) return false; return true; }
function checkReady(){
    const consent = document.getElementById('consentCheck')?.checked;
    const empty = isCanvasEmpty();
    const nombre = document.getElementById('fNombre')?.value.trim() ?? '';
    const email = document.getElementById('fEmail')?.value.trim() ?? '';
    const documento = document.getElementById('fDocumento')?.value.trim() ?? '';
    let otpOk = true;
    if (REQUIRE_OTP) { const otp = document.getElementById('otpInput')?.value.trim() ?? ''; otpOk = otp.length === 6; }
    const btn = document.getElementById('signBtn');
    if (btn) btn.disabled = !(SCROLL_OK && consent && !empty && nombre && email && documento && otpOk);
}

async function requestOtp(){
    const email = document.getElementById('fEmail').value;
    const nombre = document.getElementById('fNombre').value;
    const fd = new FormData(); fd.append('action','request_otp'); fd.append('email', email); fd.append('nombre', nombre);
    const res = await fetch('?token=' + encodeURIComponent(TOKEN), { method:'POST', body:fd });
    const data = await res.json();
    if (data.success) {
        document.getElementById('otpInput').disabled = false; document.getElementById('otpInput').focus();
        document.getElementById('otpBtn').textContent = 'Reenviar código';
    } else alert('No se pudo enviar el código.');
}

async function submitSign(){
    const fd = new FormData();
    fd.append('action','sign');
    fd.append('trazo_base64', cv.toDataURL('image/png'));
    fd.append('consent','1');
    fd.append('firmante_nombre', document.getElementById('fNombre').value);
    fd.append('firmante_email', document.getElementById('fEmail').value);
    fd.append('firmante_documento', document.getElementById('fDocumento').value);
    fd.append('firmante_cargo', document.getElementById('fCargo').value);
    if (REQUIRE_OTP) fd.append('otp_code', document.getElementById('otpInput').value);
    fd.append('signing_duration_ms', String(Date.now() - SIGN_START));
    fd.append('client_timestamp', new Date().toISOString());
    fd.append('scroll_depth_pct','100');
    const btn = document.getElementById('signBtn'); btn.disabled = true; btn.textContent = 'Firmando…';
    const res = await fetch('?token=' + encodeURIComponent(TOKEN), { method:'POST', body:fd });
    const data = await res.json();
    if (data.success) location.reload();
    else { alert('Error: ' + (data.error || 'desconocido')); btn.disabled = false; btn.textContent = '✍ Firmar contrato'; }
}

initSig();
setTimeout(updateProgress, 300);
</script>
</body>
</html>
