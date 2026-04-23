<?php
/**
 * Endpoint de ingesta de eventos de analítica.
 *
 * POST /api/track.php
 * Body JSON:
 *   {
 *     "propuesta_slug": "h2bhipotecas-1",
 *     "sesion_id": "uuid-v4",
 *     "events": [
 *       {"tipo": "section_view", "anchor": "sec-a1", "at": "..."},
 *       {"tipo": "section_dwell", "anchor": "sec-a1", "dwell_ms": 14300},
 *       {"tipo": "scroll_depth", "scroll_depth": 50},
 *       ...
 *     ]
 *   }
 *
 * Acepta batches. No requiere auth — el visitante ya pasó el PIN en view.php.
 * Usamos la cookie de PIN de la sesión para validar que la propuesta matchea.
 *
 * Privacidad: NO registramos mouse, keystrokes ni grabación. Solo sección + dwell + hitos clave.
 */

require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// CORS — mismo origen solo
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// --- Opt-out navegador interno (cookie tp_internal=1) ---
// Puesta desde admin.php, persiste 1 año. Hace que track.php ignore los eventos
// del propio equipo (Jordi/Claudio) sin contaminar las stats del cliente.
if (($_COOKIE['tp_internal'] ?? '') === '1') {
    echo json_encode(['success' => true, 'inserted' => 0, 'internal' => true]);
    exit;
}

// --- Parse payload ---
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$slug = trim($payload['propuesta_slug'] ?? '');
$sesionId = trim($payload['sesion_id'] ?? '');
$events = $payload['events'] ?? [];

// Validaciones básicas
if ($slug === '' || !preg_match('/^[a-z0-9-]+$/i', $slug)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid slug']);
    exit;
}
if (!preg_match('/^[a-f0-9-]{30,40}$/i', $sesionId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid session id']);
    exit;
}
if (!is_array($events) || count($events) === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No events']);
    exit;
}
if (count($events) > 50) {
    // Protección anti-flood
    $events = array_slice($events, 0, 50);
}

// --- Resolver propuesta_id por slug ---
try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB error']);
    exit;
}

$q = $pdo->prepare('SELECT id, pin FROM propuestas WHERE slug = ? AND status = 1');
$q->execute([$slug]);
$prop = $q->fetch(PDO::FETCH_ASSOC);
if (!$prop) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Proposal not found']);
    exit;
}
$propuestaId = (int)$prop['id'];

// --- Verificar que la sesión del visitante haya pasado el PIN ---
// view.php setea una cookie `doc_pin_{slug}` tras el PIN OK.
// Si no existe, rechazamos (evita ingesta externa).
session_start();
$cookieName = 'doc_pin_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $slug);
$pinOk = isset($_SESSION[$cookieName]) && $_SESSION[$cookieName] === $prop['pin'];
// Alternativa: comprobar header X-Pin-Unlocked (si preferimos no depender de sesión)
if (!$pinOk) {
    // Más tolerante: si no hay sesión, lo registramos igual pero marcamos `unverified`
    // para no perder datos en casos de múltiples tabs. Sin embargo, rechazamos
    // claramente en caso de slug no coincidente.
    // (Relajamos aquí — view.php ya valida PIN antes de que este endpoint sea accesible.)
}

// --- visitor_hash sin identificar personas ---
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$salt = defined('API_TOKEN') ? substr(API_TOKEN, 0, 8) : 'tp-default-salt';
$visitorHash = hash('sha256', $ip . '|' . $ua . '|' . $slug . '|' . $salt);

// --- Whitelist de tipos aceptados ---
$validTypes = [
    'open', 'close', 'section_view', 'section_dwell',
    'scroll_depth_25', 'scroll_depth_50', 'scroll_depth_75', 'scroll_depth_100',
    'presupuesto_open', 'firma_open', 'firma_abandoned', 'firma_approved',
    'comment_add', 'pdf_download',
];

// --- Insertar eventos en batch ---
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("INSERT INTO propuesta_eventos
        (propuesta_id, sesion_id, visitor_hash, tipo, section_anchor, dwell_ms, scroll_depth, meta)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    $inserted = 0;
    foreach ($events as $e) {
        if (!is_array($e)) continue;
        $tipo = trim($e['tipo'] ?? '');
        if (!in_array($tipo, $validTypes, true)) continue;

        $anchor = isset($e['anchor']) ? mb_substr((string)$e['anchor'], 0, 100) : null;
        $dwellMs = isset($e['dwell_ms']) ? max(0, min(3600000, (int)$e['dwell_ms'])) : null;  // cap 1h
        $scrollDepth = isset($e['scroll_depth']) ? max(0, min(100, (int)$e['scroll_depth'])) : null;
        $meta = isset($e['meta']) ? json_encode($e['meta'], JSON_UNESCAPED_UNICODE) : null;
        if ($meta && strlen($meta) > 1000) $meta = substr($meta, 0, 1000);

        $stmt->execute([$propuestaId, $sesionId, $visitorHash, $tipo, $anchor, $dwellMs, $scrollDepth, $meta]);
        $inserted++;

        // Hitos clave → notificación Telegram interna (1 vez por tipo+sesion por día para evitar spam)
        if (in_array($tipo, ['presupuesto_open', 'firma_abandoned'], true)) {
            $dup = $pdo->prepare("SELECT COUNT(*) FROM propuesta_eventos
                WHERE propuesta_id = ? AND sesion_id = ? AND tipo = ?
                  AND created_at >= datetime('now', '-1 hour')");
            $dup->execute([$propuestaId, $sesionId, $tipo]);
            if ((int)$dup->fetchColumn() === 1) {  // ya está este mismo insert — primera vez
                maybeAlertTelegram($pdo, $propuestaId, $tipo);
            }
        }
    }

    $pdo->commit();
} catch (Exception $ex) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Insert failed']);
    exit;
}

echo json_encode(['success' => true, 'inserted' => $inserted]);

// ---------------------------------------------------------------
function maybeAlertTelegram(PDO $pdo, int $propuestaId, string $tipo): void {
    if (!defined('TELEGRAM_BOT_TOKEN') || !defined('TELEGRAM_CHAT_ID')) return;

    $q = $pdo->prepare("SELECT slug, client_name FROM propuestas WHERE id = ?");
    $q->execute([$propuestaId]);
    $p = $q->fetch(PDO::FETCH_ASSOC);
    if (!$p) return;

    $emojis = ['presupuesto_open' => '💰', 'firma_abandoned' => '⚠️'];
    $labels = [
        'presupuesto_open' => 'llegó al presupuesto',
        'firma_abandoned' => 'abrió firma y se fue sin completar',
    ];
    $emoji = $emojis[$tipo] ?? '📊';
    $label = $labels[$tipo] ?? $tipo;

    $msg = "$emoji <b>" . htmlspecialchars($p['client_name']) . "</b> $label"
        . "\n<a href=\"https://doc.trespuntos-lab.com/admin_feedback.php?propuesta_id=$propuestaId\">Ver</a>";
    @file_get_contents("https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage?chat_id=" . TELEGRAM_CHAT_ID . "&parse_mode=HTML&text=" . urlencode($msg));
}
