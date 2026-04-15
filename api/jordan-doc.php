<?php
/**
 * Jordan-doc — endpoint de chat scopeado al documento.
 *
 * Autorización: el visitante debe tener sesión PHP con $_SESSION['auth_proposal_{id}'] = true
 * (es decir, haber pasado el PIN en /p/{slug} previamente). No se requiere bearer token
 * porque este endpoint solo responde si la sesión existe.
 *
 * Flujo:
 *  1. Valida config (API key, flag global).
 *  2. Valida propuesta + sesión + flag `enable_ai_assistant`.
 *  3. Construye system prompt (identidad + documento en bloque cacheado).
 *  4. Carga historial reciente desde `jordan_conversaciones`.
 *  5. Llama a Anthropic (Haiku + prompt caching).
 *  6. Guarda turno user y turno assistant en BD.
 *  7. Devuelve JSON { success, reply, session_id, usage }.
 */

session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

function jfail(int $code, string $msg, array $extra = []): void {
    http_response_code($code);
    echo json_encode(array_merge(['success' => false, 'error' => $msg], $extra));
    exit;
}

// Config
if (!JORDAN_DOC_ENABLED) jfail(503, 'Jordan-doc desactivado.');
if (!defined('ANTHROPIC_API_KEY') || !ANTHROPIC_API_KEY) jfail(503, 'API key no configurada.');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jfail(405, 'Método no permitido.');

// Input
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) jfail(400, 'JSON inválido.');

$propuestaId = (int)($body['propuesta_id'] ?? 0);
$slug = trim($body['slug'] ?? '');
$message = trim($body['message'] ?? '');
$sessionId = trim($body['session_id'] ?? '');
$reset = !empty($body['reset']);

if (!$propuestaId && !$slug) jfail(400, 'Falta propuesta_id o slug.');
if ($message === '' && !$reset) jfail(400, 'Mensaje vacío.');
if (mb_strlen($message) > 2000) jfail(400, 'Mensaje demasiado largo (máx 2000 chars).');

$pdo = getDBConnection();

// Propuesta
if ($propuestaId) {
    $stmt = $pdo->prepare("SELECT * FROM propuestas WHERE id = ?");
    $stmt->execute([$propuestaId]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM propuestas WHERE slug = ?");
    $stmt->execute([$slug]);
}
$prop = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$prop) jfail(404, 'Propuesta no encontrada.');
$propuestaId = (int)$prop['id'];

// Flag enable_ai_assistant
if (isset($prop['enable_ai_assistant']) && (int)$prop['enable_ai_assistant'] === 0) {
    jfail(403, 'Jordan-doc desactivado en esta propuesta.');
}

// Autorización por sesión PIN
$sessionKey = 'auth_proposal_' . $propuestaId;
if (empty($_SESSION[$sessionKey])) jfail(401, 'Sesión no autorizada. Vuelve a introducir el PIN.');

// Session_id: generar uno si no hay
if ($sessionId === '' || !preg_match('/^[a-f0-9]{16,64}$/i', $sessionId)) {
    $sessionId = bin2hex(random_bytes(16));
}

// Reset: no llama a Anthropic, solo devuelve nueva session_id
if ($reset) {
    echo json_encode(['success' => true, 'session_id' => $sessionId, 'reset' => true]);
    exit;
}

// Cargar html_content limpio (mismo tratamiento que view.php)
$html = $prop['html_content'] ?? '';
$html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
$html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
// Recortar si es muy largo (Haiku 200K context, pero para coste mantenemos <60K chars)
$htmlForPrompt = mb_substr($html, 0, 60000);

// Historial reciente (últimos 20 turnos del mismo session_id)
$histStmt = $pdo->prepare("SELECT role, content FROM jordan_conversaciones WHERE propuesta_id = ? AND session_id = ? ORDER BY id ASC LIMIT 40");
$histStmt->execute([$propuestaId, $sessionId]);
$historyRows = $histStmt->fetchAll(PDO::FETCH_ASSOC);

$messages = [];
foreach ($historyRows as $h) {
    $messages[] = ['role' => $h['role'], 'content' => $h['content']];
}
$messages[] = ['role' => 'user', 'content' => $message];

// System prompt. Bloque 2 (documento) lleva cache_control para reutilizar entre turnos.
$identityPrompt = <<<PROMPT
Eres **Jordan**, agente conversacional de Tres Puntos (agencia UX/UI y Arquitectura Digital de Conversión, Barcelona). El visitante ya tiene la propuesta abierta y ha pasado el PIN.

## Regla maestra — Scope cerrado
Solo respondes sobre:
a) Esta propuesta concreta (que recibes en el bloque "DOCUMENTO").
b) Tres Puntos como agencia (equipo, metodología, servicios).
c) Tecnologías mencionadas en el documento cuando piden aclaración.

Fuera de scope → redirige a Jordi. Nunca hables de otros clientes u otras propuestas.

## Regla anti-alucinación
Antes de afirmar algo sobre alcance, precios, plazos, integraciones o tecnologías del proyecto, cita la sección del DOCUMENTO que lo respalda. Si no puedes citarla, di: "No está recogido en esta versión del documento — le paso la pregunta a Jordi."

## Tono — hereda del Jordan web
- Nunca empieces con "Perfecto", "Entendido", "Claro", "Genial", "Por supuesto". Directo, sin acusar recibo.
- Frases cortas (máx ~20 palabras). Una pregunta por mensaje.
- Primera persona del plural: "construimos", "diseñamos".
- Sin emojis salvo que el cliente los use primero.
- Sin fórmulas corporativas vacías ("no dude en", "estaremos encantados").
- Vocabulario: usa "plataforma digital" (no "página web"), "construir", "arquitectura digital de conversión". Nunca "soluciones 360", "transformación digital", "innovador", "sinergia", "holístico".

## Aprobación y comentarios
Si el cliente dice "apruebo X" o "me gusta Y":
→ "Para formalizar la aprobación pulsa el botón 'Firmar y aprobar' en la sección correspondiente. Guardamos tu nombre, fecha y versión como justificante."
Nunca apruebes en su nombre.

Si hace una pregunta con disconformidad seria, pide un cambio de alcance, descuento o cambio de plazo:
→ "Eso es decisión de Jordi. Se lo paso y te responde hoy." (No negocies alcance en chat.)

## Cliente actual
{{CLIENT_META}}

## Lo que NUNCA haces
1. Hablar de otra propuesta u otro cliente.
2. Inventar alcance, precios, plazos o tecnologías que no estén en el documento.
3. Negociar descuentos o cambios de alcance.
4. Aprobar en nombre del cliente.
5. Pedir datos personales (ya los tenemos).
6. Sonar a bot genérico.
7. Contradecir el documento — si el cliente recuerda algo distinto, cita el documento.
PROMPT;

$clientMeta = sprintf(
    "Cliente: %s\nPropuesta: %s (slug %s)\nVersión: %s",
    $prop['client_name'] ?? '—',
    $prop['client_name'] ?? '—',
    $prop['slug'] ?? '—',
    $prop['version'] ?? '—'
);
$identityPrompt = str_replace('{{CLIENT_META}}', $clientMeta, $identityPrompt);

$systemBlocks = [
    ['type' => 'text', 'text' => $identityPrompt],
    [
        'type' => 'text',
        'text' => "DOCUMENTO (HTML de la propuesta actual). Cita secciones de aquí cuando respondas.\n\n" . $htmlForPrompt,
        'cache_control' => ['type' => 'ephemeral'],
    ],
];

// Llamada a Anthropic
$payload = [
    'model' => ANTHROPIC_MODEL,
    'max_tokens' => 1024,
    'system' => $systemBlocks,
    'messages' => $messages,
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 8,
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($resp === false) jfail(502, 'Error de red al llamar Anthropic: ' . $curlErr);
$data = json_decode($resp, true);
if ($httpCode >= 400 || !is_array($data)) {
    jfail(502, 'Respuesta inválida Anthropic (' . $httpCode . ')', ['detail' => mb_substr($resp, 0, 500)]);
}
if (isset($data['error'])) {
    jfail(502, 'Anthropic: ' . ($data['error']['message'] ?? 'error'), ['type' => $data['error']['type'] ?? '']);
}

// Extraer texto
$reply = '';
foreach (($data['content'] ?? []) as $blk) {
    if (($blk['type'] ?? '') === 'text') $reply .= $blk['text'];
}
$reply = trim($reply);
if ($reply === '') jfail(502, 'Respuesta vacía de Anthropic.');

$usage = $data['usage'] ?? [];
$tokIn = (int)($usage['input_tokens'] ?? 0);
$tokOut = (int)($usage['output_tokens'] ?? 0);
$tokCacheRead = (int)($usage['cache_read_input_tokens'] ?? 0);
$tokCacheCreate = (int)($usage['cache_creation_input_tokens'] ?? 0);

// Persistir turnos en BD
$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$insert = $pdo->prepare("INSERT INTO jordan_conversaciones (propuesta_id, session_id, role, content, tokens_in, tokens_out, tokens_cache_read, tokens_cache_create, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
$insert->execute([$propuestaId, $sessionId, 'user', $message, 0, 0, 0, 0, $ip]);
$insert->execute([$propuestaId, $sessionId, 'assistant', $reply, $tokIn, $tokOut, $tokCacheRead, $tokCacheCreate, $ip]);

echo json_encode([
    'success' => true,
    'reply' => $reply,
    'session_id' => $sessionId,
    'usage' => [
        'in' => $tokIn, 'out' => $tokOut,
        'cache_read' => $tokCacheRead, 'cache_create' => $tokCacheCreate,
    ],
]);
