<?php
/**
 * Tres Puntos — API REST para Agentes de IA
 *
 * Endpoints:
 *   GET    /api/proposals.php              — Listar propuestas
 *   GET    /api/proposals.php?id=X         — Detalle de una propuesta
 *   GET    /api/proposals.php?id=X&history=1 — Historial de versiones
 *   POST   /api/proposals.php              — Crear propuesta
 *   PUT    /api/proposals.php?id=X         — Actualizar propuesta
 *   GET    /api/proposals.php?action=team  — Listar equipo
 *   GET    /api/proposals.php?action=schema — Schema para agentes
 *
 * Autenticacion: Header "Authorization: Bearer {API_TOKEN}"
 */

// Cargar configuracion
require_once __DIR__ . '/../config.php';

// === CORS & Headers ===
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// CORS — restringir en produccion si necesitas
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

// === Helper: JSON response ===
function jsonResponse($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function jsonError(string $message, int $code = 400): void {
    jsonResponse(['success' => false, 'error' => $message], $code);
}

function jsonSuccess($data, int $code = 200): void {
    jsonResponse(['success' => true, 'data' => $data], $code);
}

// === Autenticacion ===
function authenticateRequest(): void {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    if (empty($header)) {
        jsonError('Missing Authorization header. Use: Bearer {token}', 401);
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        jsonError('Invalid Authorization format. Use: Bearer {token}', 401);
    }

    $token = trim($matches[1]);

    if (!defined('API_TOKEN') || !hash_equals(API_TOKEN, $token)) {
        jsonError('Invalid API token', 401);
    }
}

// Autenticar todas las requests
authenticateRequest();

// === DB Connection ===
try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    jsonError('Database connection failed', 500);
}

// === Routing ===
$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$action = $_GET['action'] ?? null;

// --- GET: Acciones especiales ---
if ($method === 'GET' && $action === 'schema') {
    handleSchema();
}

if ($method === 'GET' && $action === 'team') {
    handleTeam($pdo);
}

// --- POST: Restore version ---
if ($method === 'POST' && $action === 'restore' && $id) {
    handleRestore($pdo, $id);
}

// --- Comments / feedback loop ---
if ($method === 'GET' && $action === 'comments' && $id) {
    handleListComments($pdo, $id);
}
if ($method === 'GET' && $action === 'thread' && $id) {
    handleGetThread($pdo, $id);
}
if ($method === 'POST' && $action === 'reply_draft' && $id) {
    handleReply($pdo, $id, true);   // crea borrador (is_draft=1)
}
if ($method === 'POST' && $action === 'reply_publish' && $id) {
    handleReply($pdo, $id, false);  // crea publicada directamente
}
if ($method === 'POST' && $action === 'publish_reply' && $id) {
    handlePublishReply($pdo, $id);
}
if ($method === 'POST' && $action === 'discard_reply' && $id) {
    handleDiscardReply($pdo, $id);
}
if ($method === 'POST' && $action === 'resolve' && $id) {
    handleResolveComment($pdo, $id);
}
if ($method === 'POST' && $action === 'notify' && $id) {
    handleMarkNotified($pdo, $id);
}

// --- Provider messages (mismo patrón que comments, pero contra proveedor_mensajes) ---
if ($method === 'GET' && $action === 'provider_messages' && $id) {
    handleListProviderMessages($pdo, $id);
}
if ($method === 'POST' && $action === 'provider_reply_draft' && $id) {
    handleProviderReply($pdo, $id, true);
}
if ($method === 'POST' && $action === 'provider_reply_publish' && $id) {
    handleProviderReply($pdo, $id, false);
}
if ($method === 'POST' && $action === 'provider_new_thread_draft' && $id) {
    handleProviderNewThread($pdo, $id, true);   // ?id=PROVEEDOR_ID
}
if ($method === 'POST' && $action === 'provider_new_thread_publish' && $id) {
    handleProviderNewThread($pdo, $id, false);  // ?id=PROVEEDOR_ID
}
if ($method === 'POST' && $action === 'provider_publish_reply' && $id) {
    handleProviderPublishReply($pdo, $id);
}
if ($method === 'POST' && $action === 'provider_discard_reply' && $id) {
    handleProviderDiscardReply($pdo, $id);
}
if ($method === 'POST' && $action === 'provider_resolve' && $id) {
    handleProviderResolve($pdo, $id);
}
if ($method === 'POST' && $action === 'provider_notify' && $id) {
    handleProviderMarkNotified($pdo, $id);
}
if ($method === 'GET' && $action === 'provider_budget_download' && $id) {
    handleProviderBudgetDownload($pdo, $id);
}

// --- CRUD ---
switch ($method) {
    case 'GET':
        if ($id) {
            if (isset($_GET['history'])) {
                handleGetHistory($pdo, $id);
            } else {
                handleGetOne($pdo, $id);
            }
        } else {
            handleList($pdo);
        }
        break;

    case 'POST':
        handleCreate($pdo);
        break;

    case 'PUT':
        if (!$id) {
            jsonError('PUT requires ?id=X parameter');
        }
        handleUpdate($pdo, $id);
        break;

    default:
        jsonError('Method not allowed. Use GET, POST, or PUT.', 405);
}

// ============================================================
// HANDLERS
// ============================================================

/**
 * GET /api/proposals.php — Listar propuestas
 */
function handleList(PDO $pdo): void {
    $stmt = $pdo->query("
        SELECT p.id, p.slug, p.client_name, p.status, p.version,
               p.created_at, p.sent_date, p.views_count,
               p.presupuesto_pdf IS NOT NULL as has_pdf
        FROM propuestas p
        ORDER BY p.created_at DESC
    ");
    $proposals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cargar aprobaciones para cada propuesta
    $appStmt = $pdo->query("SELECT propuesta_id, tipo, aprobado_at FROM aprobaciones");
    $approvals = [];
    foreach ($appStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $approvals[$a['propuesta_id']][$a['tipo']] = $a['aprobado_at'];
    }

    foreach ($proposals as &$p) {
        $p['has_pdf'] = (bool)$p['has_pdf'];
        $p['approvals'] = $approvals[$p['id']] ?? [];
    }

    jsonSuccess($proposals);
}

/**
 * GET /api/proposals.php?id=X — Detalle completo
 */
function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT * FROM propuestas WHERE id = ?");
    $stmt->execute([$id]);
    $proposal = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$proposal) {
        jsonError('Proposal not found', 404);
    }

    // Aprobaciones
    $appStmt = $pdo->prepare("SELECT tipo, aprobado_at, ip_address FROM aprobaciones WHERE propuesta_id = ?");
    $appStmt->execute([$id]);
    $proposal['approvals'] = $appStmt->fetchAll(PDO::FETCH_ASSOC);

    // Feedback
    $fbStmt = $pdo->prepare("SELECT tipo_accion, comentario, created_at FROM feedback_presupuesto WHERE propuesta_id = ? ORDER BY created_at DESC");
    $fbStmt->execute([$id]);
    $proposal['feedback'] = $fbStmt->fetchAll(PDO::FETCH_ASSOC);

    // Equipo asignado
    $equipo_ids = json_decode($proposal['equipo_ids'] ?? '[]', true);
    if (!empty($equipo_ids)) {
        $placeholders = implode(',', array_fill(0, count($equipo_ids), '?'));
        $eqStmt = $pdo->prepare("SELECT id, nombre, cargo, descripcion, foto_url FROM equipo WHERE id IN ($placeholders) ORDER BY orden");
        $eqStmt->execute($equipo_ids);
        $proposal['team'] = $eqStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $proposal['team'] = [];
    }

    jsonSuccess($proposal);
}

/**
 * GET /api/proposals.php?id=X&history=1 — Historial de versiones
 */
/**
 * POST /api/proposals.php?id=X&action=restore — Restaurar version del historial
 * Body: {"history_id": 3} o {"version": "v1.0"}
 */
function handleRestore(PDO $pdo, int $id): void {
    $input = json_decode(file_get_contents('php://input'), true);

    // Verificar propuesta existe
    $current = $pdo->prepare("SELECT * FROM propuestas WHERE id = ?");
    $current->execute([$id]);
    $proposal = $current->fetch(PDO::FETCH_ASSOC);
    if (!$proposal) {
        jsonError('Proposal not found', 404);
    }

    // Buscar version a restaurar: por history_id o por version label
    if (!empty($input['history_id'])) {
        $stmt = $pdo->prepare("SELECT id, version, html_content FROM propuestas_history WHERE id = ? AND propuesta_id = ?");
        $stmt->execute([(int)$input['history_id'], $id]);
    } elseif (!empty($input['version'])) {
        $stmt = $pdo->prepare("SELECT id, version, html_content FROM propuestas_history WHERE version = ? AND propuesta_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([trim($input['version']), $id]);
    } else {
        jsonError('Provide history_id or version to restore');
    }

    $historyRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$historyRecord) {
        jsonError('Version not found in history', 404);
    }

    // Guardar version actual en historial antes de restaurar
    $saveHistory = $pdo->prepare("INSERT INTO propuestas_history (propuesta_id, version, html_content) VALUES (?, ?, ?)");
    $saveHistory->execute([$id, $proposal['version'], $proposal['html_content']]);

    // Restaurar
    $update = $pdo->prepare("UPDATE propuestas SET html_content = :html, version = :version WHERE id = :id");
    $update->execute([
        ':html' => $historyRecord['html_content'],
        ':version' => $historyRecord['version'],
        ':id' => $id
    ]);

    jsonSuccess([
        'id' => $id,
        'restored_version' => $historyRecord['version'],
        'previous_version_saved' => $proposal['version'],
        'message' => "Restored to {$historyRecord['version']}. Previous version ({$proposal['version']}) saved to history."
    ]);
}

function handleGetHistory(PDO $pdo, int $id): void {
    // Verificar que existe
    $check = $pdo->prepare("SELECT id, slug, client_name FROM propuestas WHERE id = ?");
    $check->execute([$id]);
    if (!$check->fetch()) {
        jsonError('Proposal not found', 404);
    }

    $stmt = $pdo->prepare("
        SELECT id, version, created_at, LENGTH(html_content) as content_length
        FROM propuestas_history
        WHERE propuesta_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$id]);

    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * POST /api/proposals.php — Crear propuesta
 */
function handleCreate(PDO $pdo): void {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        jsonError('Invalid JSON body');
    }

    // Validar campos obligatorios
    $required = ['slug', 'client_name', 'pin', 'html_content'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            jsonError("Missing required field: $field");
        }
    }

    // Sanitizar slug
    $slug = preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower(trim($input['slug'])));
    $client_name = trim($input['client_name']);
    $pin = trim($input['pin']);
    $html_content = $input['html_content'];
    $sent_date = !empty($input['sent_date']) ? trim($input['sent_date']) : null;
    $version = !empty($input['version']) ? trim($input['version']) : 'v1.0';
    $equipo_ids = isset($input['equipo_ids']) && is_array($input['equipo_ids'])
        ? json_encode(array_map('intval', $input['equipo_ids']))
        : '[]';

    // Auto-increment slug si ya existe (misma logica que admin.php)
    $original_slug = $slug;
    $counter = 1;
    while (true) {
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM propuestas WHERE slug = ?");
        $checkStmt->execute([$slug]);
        if ($checkStmt->fetchColumn() == 0) {
            break;
        }
        $slug = $original_slug . '-' . $counter;
        $counter++;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO propuestas (slug, client_name, pin, html_content, sent_date, version, equipo_ids)
            VALUES (:slug, :name, :pin, :html, :sent_date, :version, :equipo_ids)
        ");
        $stmt->execute([
            ':slug' => $slug,
            ':name' => $client_name,
            ':pin' => $pin,
            ':html' => $html_content,
            ':sent_date' => $sent_date,
            ':version' => $version,
            ':equipo_ids' => $equipo_ids
        ]);

        $newId = $pdo->lastInsertId();

        // Notificar por Telegram
        notifyTelegram("📄 Nueva propuesta creada via API\n\nCliente: $client_name\nSlug: $slug\nID: $newId");

        jsonSuccess([
            'id' => (int)$newId,
            'slug' => $slug,
            'client_name' => $client_name,
            'version' => $version,
            'message' => "Proposal created successfully"
        ], 201);

    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            jsonError('Duplicate slug', 409);
        }
        jsonError('Database error: ' . $e->getMessage(), 500);
    }
}

/**
 * PUT /api/proposals.php?id=X — Actualizar propuesta
 */
function handleUpdate(PDO $pdo, int $id): void {
    // Verificar que existe
    $existing = $pdo->prepare("SELECT * FROM propuestas WHERE id = ?");
    $existing->execute([$id]);
    $current = $existing->fetch(PDO::FETCH_ASSOC);

    if (!$current) {
        jsonError('Proposal not found', 404);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        jsonError('Invalid JSON body');
    }

    // Solo guardar en historial si el agente lo pide explicitamente con "save_version": true
    if (!empty($input['save_version'])) {
        $insertHistory = $pdo->prepare("INSERT INTO propuestas_history (propuesta_id, version, html_content) VALUES (?, ?, ?)");
        $insertHistory->execute([$id, $current['version'], $current['html_content']]);
    }

    // Merge: solo actualizar campos enviados
    $slug = isset($input['slug'])
        ? preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower(trim($input['slug'])))
        : $current['slug'];
    $client_name = isset($input['client_name']) ? trim($input['client_name']) : $current['client_name'];
    $pin = isset($input['pin']) ? trim($input['pin']) : $current['pin'];
    $html_content = $input['html_content'] ?? $current['html_content'];
    $sent_date = array_key_exists('sent_date', $input) ? ($input['sent_date'] ?: null) : $current['sent_date'];
    $version = isset($input['version']) ? trim($input['version']) : $current['version'];
    $equipo_ids = isset($input['equipo_ids']) && is_array($input['equipo_ids'])
        ? json_encode(array_map('intval', $input['equipo_ids']))
        : $current['equipo_ids'];

    // Verificar slug unico (excluyendo el actual)
    if ($slug !== $current['slug']) {
        $original_slug = $slug;
        $counter = 1;
        while (true) {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM propuestas WHERE slug = ? AND id != ?");
            $checkStmt->execute([$slug, $id]);
            if ($checkStmt->fetchColumn() == 0) {
                break;
            }
            $slug = $original_slug . '-' . $counter;
            $counter++;
        }
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE propuestas SET
                slug = :slug, client_name = :name, pin = :pin,
                html_content = :html, sent_date = :sent_date,
                version = :version, equipo_ids = :equipo_ids
            WHERE id = :id
        ");
        $stmt->execute([
            ':slug' => $slug,
            ':name' => $client_name,
            ':pin' => $pin,
            ':html' => $html_content,
            ':sent_date' => $sent_date,
            ':version' => $version,
            ':equipo_ids' => $equipo_ids,
            ':id' => $id
        ]);

        $msg = !empty($input['save_version'])
            ? "Proposal updated. Previous version ($current[version]) saved to history."
            : "Proposal updated (draft — no history saved).";

        jsonSuccess([
            'id' => $id,
            'slug' => $slug,
            'version' => $version,
            'history_saved' => !empty($input['save_version']),
            'message' => $msg
        ]);

    } catch (PDOException $e) {
        jsonError('Database error: ' . $e->getMessage(), 500);
    }
}

/**
 * GET /api/proposals.php?action=team — Listar equipo
 */
function handleTeam(PDO $pdo): void {
    $stmt = $pdo->query("SELECT id, nombre, cargo, descripcion, foto_url, orden FROM equipo ORDER BY orden");
    jsonSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * GET /api/proposals.php?action=schema — Schema para agentes
 */
function handleSchema(): void {
    jsonSuccess([
        'name' => 'Tres Puntos Proposal API',
        'version' => '1.0',
        'description' => 'API para crear y gestionar propuestas/documentos funcionales de Tres Puntos',
        'endpoints' => [
            [
                'method' => 'GET',
                'path' => '/api/proposals.php',
                'description' => 'Listar todas las propuestas'
            ],
            [
                'method' => 'GET',
                'path' => '/api/proposals.php?id={id}',
                'description' => 'Obtener detalle completo de una propuesta (incluye html_content, equipo, aprobaciones)'
            ],
            [
                'method' => 'GET',
                'path' => '/api/proposals.php?id={id}&history=1',
                'description' => 'Historial de versiones de una propuesta'
            ],
            [
                'method' => 'POST',
                'path' => '/api/proposals.php',
                'description' => 'Crear nueva propuesta'
            ],
            [
                'method' => 'PUT',
                'path' => '/api/proposals.php?id={id}',
                'description' => 'Actualizar propuesta existente (guarda version anterior automaticamente)'
            ],
            [
                'method' => 'GET',
                'path' => '/api/proposals.php?action=team',
                'description' => 'Listar miembros del equipo disponibles'
            ],
            [
                'method' => 'POST',
                'path' => '/api/proposals.php?id={id}&action=restore',
                'description' => 'Restaurar una version anterior. Body: {"history_id": X} o {"version": "v1.0"}. Guarda la version actual en historial antes de restaurar.'
            ],
            [
                'method' => 'GET',
                'path' => '/api/proposals.php?id={propuesta_id}&action=provider_messages',
                'description' => 'Lista los proveedores de la propuesta y sus hilos de mensajes. Opcionales: proveedor_id={N} para filtrar a uno, include_drafts=1 para incluir borradores staff, status=open|closed|all.'
            ],
            [
                'method' => 'POST',
                'path' => '/api/proposals.php?id={parent_msg_id}&action=provider_reply_draft',
                'description' => 'Crea respuesta staff a un mensaje de proveedor como borrador (no visible al proveedor). Body: {"texto": "..."}'
            ],
            [
                'method' => 'POST',
                'path' => '/api/proposals.php?id={parent_msg_id}&action=provider_reply_publish',
                'description' => 'Crea respuesta staff publicada (visible al proveedor + ping Telegram). Body: {"texto": "..."}'
            ],
            [
                'method' => 'POST',
                'path' => '/api/proposals.php?id={proveedor_id}&action=provider_new_thread_draft',
                'description' => 'Abre un hilo NUEVO staff→proveedor como borrador (TP inicia conversación; útil para trasladar dudas del cliente al proveedor). Body: {"texto": "...", "section_anchor": "sec-1-1", "section_title": "1.1 Situación", "autor_nombre": "Claudio"}. autor_nombre opcional (default Tres Puntos).'
            ],
            [
                'method' => 'POST',
                'path' => '/api/proposals.php?id={proveedor_id}&action=provider_new_thread_publish',
                'description' => 'Abre un hilo NUEVO staff→proveedor publicado (visible inmediatamente + ping Telegram). Mismo body que provider_new_thread_draft.'
            ],
            [
                'method' => 'POST',
                'path' => '/api/proposals.php?id={reply_id}&action=provider_publish_reply',
                'description' => 'Publica un borrador staff existente. Body opcional: {"texto": "texto editado"}.'
            ],
            [
                'method' => 'POST',
                'path' => '/api/proposals.php?id={reply_id}&action=provider_discard_reply',
                'description' => 'Borra un borrador staff de proveedor.'
            ],
            [
                'method' => 'POST',
                'path' => '/api/proposals.php?id={root_msg_id}&action=provider_resolve',
                'description' => 'Cierra/reabre un hilo de proveedor (toggle).'
            ],
            [
                'method' => 'POST',
                'path' => '/api/proposals.php?id={propuesta_id}&action=provider_notify',
                'description' => 'Marca como notificadas las respuestas staff publicadas (para evitar reenviar emails).'
            ],
            [
                'method' => 'GET',
                'path' => '/api/proposals.php?id={budget_id}&action=provider_budget_download',
                'description' => 'Descarga el PDF de un presupuesto subido por proveedor con Bearer auth. Marca seen_at automáticamente. Devuelve binary stream con Content-Type: application/pdf.'
            ]
        ],
        'create_fields' => [
            'slug' => ['type' => 'string', 'required' => true, 'description' => 'URL slug (se auto-sanitiza y auto-incrementa si duplicado)'],
            'client_name' => ['type' => 'string', 'required' => true, 'description' => 'Nombre del cliente'],
            'pin' => ['type' => 'string', 'required' => true, 'description' => 'PIN de 4 digitos para acceso del cliente'],
            'html_content' => ['type' => 'string', 'required' => true, 'description' => 'Contenido HTML completo de la propuesta/documento funcional'],
            'sent_date' => ['type' => 'string', 'required' => false, 'description' => 'Fecha de envio (YYYY-MM-DD)', 'example' => '2026-04-07'],
            'version' => ['type' => 'string', 'required' => false, 'description' => 'Etiqueta de version', 'default' => 'v1.0', 'example' => 'v1.0'],
            'equipo_ids' => ['type' => 'array', 'required' => false, 'description' => 'IDs de miembros del equipo asignados (usa GET ?action=team para ver disponibles)', 'example' => [1, 3]]
        ],
        'update_fields' => [
            'note' => 'Mismos campos que create, pero todos opcionales. Solo se actualizan los campos enviados.',
            'save_version' => [
                'type' => 'boolean',
                'required' => false,
                'default' => false,
                'description' => 'Si es true, guarda el documento actual en el historial ANTES de sobreescribir. Usa esto solo cuando el documento esta listo y quieres crear una version oficial (v1.1, v2.0, etc). NO lo uses en ajustes intermedios (correcciones de texto, CSS, etc).'
            ]
        ],
        'authentication' => 'Header "Authorization: Bearer {API_TOKEN}"',
        'example_create' => [
            'slug' => 'cliente-proyecto-web',
            'client_name' => 'Acme Corp',
            'pin' => '1234',
            'html_content' => '<h1>Documento Funcional</h1><p>Contenido...</p>',
            'version' => 'v1.0',
            'equipo_ids' => [1, 2]
        ]
    ]);
}

// === Telegram Notification ===
function notifyTelegram(string $text): void {
    if (!defined('TELEGRAM_BOT_TOKEN') || !defined('TELEGRAM_CHAT_ID')) {
        return;
    }

    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'chat_id' => TELEGRAM_CHAT_ID,
            'text' => $text,
            'parse_mode' => 'HTML'
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ============================================================
// COMMENTS / FEEDBACK LOOP
// ============================================================

/**
 * GET /api/proposals.php?id=X&action=comments[&status=open|closed|all][&include_drafts=1]
 * Lista todos los comentarios de una propuesta, agrupados por hilo.
 */
function handleListComments(PDO $pdo, int $propuestaId): void {
    $status = $_GET['status'] ?? 'all';
    $includeDrafts = !empty($_GET['include_drafts']);

    $sql = "SELECT id, section_anchor, section_title, autor_nombre, autor_apellidos, autor_email,
                   texto, parent_id, resuelto, resuelto_por, resuelto_at, is_staff,
                   COALESCE(is_draft, 0) AS is_draft, notificado_at, created_at
            FROM comentarios_seccion
            WHERE propuesta_id = ?";
    $params = [$propuestaId];
    if (!$includeDrafts) { $sql .= " AND (is_draft IS NULL OR is_draft = 0)"; }
    $sql .= " ORDER BY COALESCE(parent_id, id) ASC, created_at ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $threads = [];
    $byRoot = [];
    foreach ($all as $c) {
        if (empty($c['parent_id'])) {
            $c['replies'] = [];
            $byRoot[$c['id']] = &$c;
            $threads[] = &$c;
            unset($c);
        }
    }
    foreach ($all as $c) {
        if (!empty($c['parent_id']) && isset($byRoot[$c['parent_id']])) {
            $byRoot[$c['parent_id']]['replies'][] = $c;
        }
    }

    if ($status === 'open')   $threads = array_values(array_filter($threads, fn($t) => (int)$t['resuelto'] === 0));
    if ($status === 'closed') $threads = array_values(array_filter($threads, fn($t) => (int)$t['resuelto'] === 1));

    jsonSuccess([
        'propuesta_id' => $propuestaId,
        'total' => count($threads),
        'threads' => $threads,
    ]);
}

function handleGetThread(PDO $pdo, int $commentId): void {
    $root = $pdo->prepare("SELECT id, propuesta_id, section_anchor, section_title, autor_nombre, autor_apellidos,
                                  autor_email, texto, resuelto, resuelto_por, resuelto_at, created_at
                           FROM comentarios_seccion WHERE id = ? AND parent_id IS NULL");
    $root->execute([$commentId]);
    $r = $root->fetch(PDO::FETCH_ASSOC);
    if (!$r) jsonError('Comentario raíz no encontrado', 404);

    $replies = $pdo->prepare("SELECT id, autor_nombre, autor_apellidos, texto, is_staff,
                                     COALESCE(is_draft, 0) AS is_draft, notificado_at, created_at
                              FROM comentarios_seccion WHERE parent_id = ? ORDER BY created_at ASC");
    $replies->execute([$commentId]);
    $r['replies'] = $replies->fetchAll(PDO::FETCH_ASSOC);
    jsonSuccess($r);
}

/**
 * POST /api/proposals.php?id=COMMENT_ID&action=reply_draft
 * Body JSON: { "texto": "…" }
 * Crea respuesta staff (is_staff=1). Si $isDraft=true → queda como borrador (cliente no la ve).
 */
function handleReply(PDO $pdo, int $parentId, bool $isDraft): void {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $texto = trim($input['texto'] ?? '');
    if ($texto === '' || mb_strlen($texto) > 4000) jsonError('Texto inválido (vacío o >4000 caracteres)', 422);

    $parent = $pdo->prepare("SELECT propuesta_id, section_anchor, section_title FROM comentarios_seccion WHERE id = ?");
    $parent->execute([$parentId]);
    $p = $parent->fetch(PDO::FETCH_ASSOC);
    if (!$p) jsonError('Comentario raíz no encontrado', 404);

    $pdo->prepare("INSERT INTO comentarios_seccion
        (propuesta_id, section_anchor, section_title, autor_nombre, autor_apellidos, autor_email,
         texto, parent_id, is_staff, is_draft, ip_address, user_agent)
        VALUES (?, ?, ?, 'Tres Puntos', '', 'hola@trespuntoscomunicacion.es', ?, ?, 1, ?, ?, ?)")
        ->execute([
            $p['propuesta_id'], $p['section_anchor'], $p['section_title'],
            $texto, $parentId, $isDraft ? 1 : 0,
            $_SERVER['REMOTE_ADDR'] ?? null,
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'api', 0, 255),
        ]);
    $id = (int)$pdo->lastInsertId();

    if (!$isDraft) {
        $resumen = mb_substr($texto, 0, 120) . (mb_strlen($texto) > 120 ? '…' : '');
        notifyTelegram("✅ Respuesta via API · <i>" . htmlspecialchars($p['section_title'] ?: $p['section_anchor']) . "</i>\n" . htmlspecialchars($resumen));
    }

    jsonSuccess(['id' => $id, 'is_draft' => $isDraft, 'parent_id' => $parentId]);
}

function handlePublishReply(PDO $pdo, int $replyId): void {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $texto = isset($input['texto']) ? trim($input['texto']) : null;

    $row = $pdo->prepare("SELECT is_staff, is_draft, texto, section_anchor, section_title FROM comentarios_seccion WHERE id = ?");
    $row->execute([$replyId]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if (!$r) jsonError('Reply no encontrado', 404);
    if ((int)$r['is_staff'] !== 1) jsonError('Solo se publican respuestas staff', 422);
    if ((int)$r['is_draft'] !== 1) jsonError('La respuesta ya está publicada', 409);

    $textoFinal = $texto !== null && $texto !== '' ? $texto : $r['texto'];
    if (mb_strlen($textoFinal) > 4000) jsonError('Texto demasiado largo', 422);

    $pdo->prepare("UPDATE comentarios_seccion SET texto = ?, is_draft = 0 WHERE id = ?")->execute([$textoFinal, $replyId]);

    $resumen = mb_substr($textoFinal, 0, 120) . (mb_strlen($textoFinal) > 120 ? '…' : '');
    notifyTelegram("✅ Publicada via API · <i>" . htmlspecialchars($r['section_title'] ?: $r['section_anchor']) . "</i>\n" . htmlspecialchars($resumen));

    jsonSuccess(['id' => $replyId, 'published' => true]);
}

function handleDiscardReply(PDO $pdo, int $replyId): void {
    $stmt = $pdo->prepare("DELETE FROM comentarios_seccion WHERE id = ? AND is_staff = 1 AND is_draft = 1");
    $stmt->execute([$replyId]);
    if ($stmt->rowCount() === 0) jsonError('Solo se descartan borradores staff', 422);
    jsonSuccess(['discarded' => true]);
}

/**
 * POST /api/proposals.php?id=ROOT_COMMENT_ID&action=resolve
 * Body JSON: { "actor": "staff" | "author", "actor_name": "..." }
 * Cierra un hilo raíz. "staff" es override de admin (el flujo normal es que cierre el autor desde view.php).
 */
function handleResolveComment(PDO $pdo, int $rootId): void {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $actor = $input['actor'] ?? 'staff';
    $actorName = trim($input['actor_name'] ?? 'Tres Puntos');

    $row = $pdo->prepare("SELECT resuelto, parent_id, autor_nombre, autor_apellidos, section_anchor, section_title FROM comentarios_seccion WHERE id = ?");
    $row->execute([$rootId]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if (!$r) jsonError('Comentario no encontrado', 404);
    if ($r['parent_id']) jsonError('Solo se cierran comentarios raíz', 422);

    $newState = (int)$r['resuelto'] === 1 ? 0 : 1;

    if ($newState === 1) {
        $who = $actor === 'author' ? trim($r['autor_nombre'] . ' ' . $r['autor_apellidos']) : $actorName;
        $pdo->prepare("UPDATE comentarios_seccion SET resuelto = 1, resuelto_por = ?, resuelto_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$who, $rootId]);
        notifyTelegram("✅ Cerrado via API por " . htmlspecialchars($who) . " · <i>" . htmlspecialchars($r['section_title'] ?: $r['section_anchor']) . "</i>");
    } else {
        $pdo->prepare("UPDATE comentarios_seccion SET resuelto = 0, resuelto_por = NULL, resuelto_at = NULL WHERE id = ?")
            ->execute([$rootId]);
        notifyTelegram("↩️ Reabierto via API · <i>" . htmlspecialchars($r['section_title'] ?: $r['section_anchor']) . "</i>");
    }

    jsonSuccess(['id' => $rootId, 'resuelto' => $newState]);
}

function handleMarkNotified(PDO $pdo, int $propuestaId): void {
    $stmt = $pdo->prepare("UPDATE comentarios_seccion
        SET notificado_at = CURRENT_TIMESTAMP
        WHERE propuesta_id = ? AND is_staff = 1 AND (is_draft IS NULL OR is_draft = 0) AND notificado_at IS NULL");
    $stmt->execute([$propuestaId]);
    jsonSuccess(['updated' => $stmt->rowCount()]);
}

// ============================================================
// PROVIDER MESSAGES (proveedor_mensajes) — paralelo a comments
// ============================================================

/**
 * GET /api/proposals.php?id=PROPUESTA_ID&action=provider_messages[&proveedor_id=N][&include_drafts=1][&status=open|closed|all]
 * Lista proveedores de la propuesta y sus hilos de mensajes (estructura agrupada por proveedor + hilo raíz).
 */
function handleListProviderMessages(PDO $pdo, int $propuestaId): void {
    $proveedorId    = isset($_GET['proveedor_id']) ? (int)$_GET['proveedor_id'] : 0;
    $includeDrafts  = !empty($_GET['include_drafts']);
    $status         = $_GET['status'] ?? 'all';

    // Verificar que la propuesta existe
    $check = $pdo->prepare("SELECT id, slug, client_name FROM propuestas WHERE id = ?");
    $check->execute([$propuestaId]);
    $prop = $check->fetch(PDO::FETCH_ASSOC);
    if (!$prop) jsonError('Proposal not found', 404);

    // Lista de proveedores (todos o uno concreto)
    $sqlProv = "SELECT id, nombre, empresa, email, activo, last_accessed_at, accesos
                FROM propuesta_proveedores
                WHERE propuesta_id = ?";
    $params = [$propuestaId];
    if ($proveedorId) { $sqlProv .= " AND id = ?"; $params[] = $proveedorId; }
    $sqlProv .= " ORDER BY id ASC";
    $pq = $pdo->prepare($sqlProv);
    $pq->execute($params);
    $providers = $pq->fetchAll(PDO::FETCH_ASSOC);
    if (!$providers) {
        jsonSuccess(['propuesta_id' => $propuestaId, 'client_name' => $prop['client_name'], 'providers' => []]);
    }

    $provIds = array_column($providers, 'id');
    $placeholders = implode(',', array_fill(0, count($provIds), '?'));

    // Presupuestos subidos por estos proveedores (todos, sin filtro)
    // Defensivo: si seen_at no existe (pre-migración) usamos NULL.
    $budgetsByProv = [];
    try {
        $bsql = "SELECT id, proveedor_id, archivo_nombre, archivo_size, archivo_mime,
                        importe_total, plazo_dias, moneda, notas, version_num, uploaded_at,
                        seen_at
                 FROM proveedor_presupuestos
                 WHERE proveedor_id IN ($placeholders)
                 ORDER BY version_num DESC, uploaded_at DESC";
        $bq = $pdo->prepare($bsql);
        $bq->execute($provIds);
        foreach ($bq->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $b['download_url'] = '/api/proposals.php?action=provider_budget_download&id=' . (int)$b['id'];
            $budgetsByProv[(int)$b['proveedor_id']][] = $b;
        }
    } catch (\Throwable $_) {
        // tabla no existe o seen_at sin migrar → array vacío
        try {
            $bsql2 = "SELECT id, proveedor_id, archivo_nombre, archivo_size, archivo_mime,
                            importe_total, plazo_dias, moneda, notas, version_num, uploaded_at
                     FROM proveedor_presupuestos
                     WHERE proveedor_id IN ($placeholders)
                     ORDER BY version_num DESC, uploaded_at DESC";
            $bq2 = $pdo->prepare($bsql2);
            $bq2->execute($provIds);
            foreach ($bq2->fetchAll(PDO::FETCH_ASSOC) as $b) {
                $b['seen_at'] = null;
                $b['download_url'] = '/api/proposals.php?action=provider_budget_download&id=' . (int)$b['id'];
                $budgetsByProv[(int)$b['proveedor_id']][] = $b;
            }
        } catch (\Throwable $__) { /* tabla aún no creada */ }
    }

    // Mensajes de esos proveedores
    $sqlMsg = "SELECT id, proveedor_id, section_anchor, section_title, autor_tipo, autor_nombre,
                      texto, parent_id, resuelto, COALESCE(is_draft, 0) AS is_draft,
                      notificado_at, created_at
               FROM proveedor_mensajes
               WHERE proveedor_id IN ($placeholders)";
    if (!$includeDrafts) { $sqlMsg .= " AND (is_draft IS NULL OR is_draft = 0)"; }
    $sqlMsg .= " ORDER BY COALESCE(parent_id, id) ASC, created_at ASC";
    $mq = $pdo->prepare($sqlMsg);
    $mq->execute($provIds);
    $allMsgs = $mq->fetchAll(PDO::FETCH_ASSOC);

    // Agrupar por proveedor → hilo raíz → replies
    $byProv = [];
    foreach ($providers as $p) {
        $byProv[$p['id']] = $p + ['threads' => [], '_byRoot' => [], 'budgets' => $budgetsByProv[(int)$p['id']] ?? []];
    }
    foreach ($allMsgs as $m) {
        if (empty($m['parent_id'])) {
            $m['replies'] = [];
            $byProv[$m['proveedor_id']]['_byRoot'][$m['id']] = $m;
        }
    }
    foreach ($allMsgs as $m) {
        if (!empty($m['parent_id']) && isset($byProv[$m['proveedor_id']]['_byRoot'][$m['parent_id']])) {
            $byProv[$m['proveedor_id']]['_byRoot'][$m['parent_id']]['replies'][] = $m;
        }
    }
    foreach ($byProv as $pid => &$p) {
        $threads = array_values($p['_byRoot']);
        if ($status === 'open')   $threads = array_values(array_filter($threads, fn($t) => (int)$t['resuelto'] === 0));
        if ($status === 'closed') $threads = array_values(array_filter($threads, fn($t) => (int)$t['resuelto'] === 1));
        $p['threads'] = $threads;
        $p['n_threads'] = count($threads);
        unset($p['_byRoot']);
    }
    unset($p);

    jsonSuccess([
        'propuesta_id' => $propuestaId,
        'client_name'  => $prop['client_name'],
        'providers'    => array_values($byProv),
    ]);
}

/**
 * POST /api/proposals.php?id=PARENT_MSG_ID&action=provider_reply_draft|provider_reply_publish
 * Body JSON: { "texto": "..." }
 * Crea respuesta staff. Draft → cliente/proveedor no la ve. Publish → ping a Telegram.
 */
function handleProviderReply(PDO $pdo, int $parentId, bool $isDraft): void {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $texto = trim($input['texto'] ?? '');
    if ($texto === '' || mb_strlen($texto) > 4000) jsonError('Texto inválido (vacío o >4000 caracteres)', 422);

    $parent = $pdo->prepare("SELECT proveedor_id, section_anchor, section_title FROM proveedor_mensajes WHERE id = ?");
    $parent->execute([$parentId]);
    $p = $parent->fetch(PDO::FETCH_ASSOC);
    if (!$p) jsonError('Mensaje raíz no encontrado', 404);

    $pdo->prepare("INSERT INTO proveedor_mensajes
        (proveedor_id, section_anchor, section_title, autor_tipo, autor_nombre, texto, parent_id, is_draft)
        VALUES (?, ?, ?, 'staff', 'Tres Puntos', ?, ?, ?)")
        ->execute([
            $p['proveedor_id'], $p['section_anchor'], $p['section_title'],
            $texto, $parentId, $isDraft ? 1 : 0,
        ]);
    $newId = (int)$pdo->lastInsertId();

    if (!$isDraft) {
        $resumen = mb_substr($texto, 0, 120) . (mb_strlen($texto) > 120 ? '…' : '');
        notifyTelegram("✅ Respuesta a proveedor via API · <i>" . htmlspecialchars($p['section_title'] ?: ($p['section_anchor'] ?: 'general')) . "</i>\n" . htmlspecialchars($resumen));
    }

    jsonSuccess(['id' => $newId, 'is_draft' => $isDraft, 'parent_id' => $parentId]);
}

/**
 * POST /api/proposals.php?id=PROVEEDOR_ID&action=provider_new_thread_draft|provider_new_thread_publish
 * Body JSON: { "texto": "...", "section_anchor": "sec-1-1", "section_title": "1.1 …", "autor_nombre": "Claudio" }
 *
 * Crea un hilo NUEVO (root) staff→proveedor. Útil para trasladar dudas del cliente al proveedor
 * sin esperar a que el proveedor abra una conversación primero.
 */
function handleProviderNewThread(PDO $pdo, int $proveedorId, bool $isDraft): void {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $texto = trim($input['texto'] ?? '');
    $sectionAnchor = trim($input['section_anchor'] ?? '');
    $sectionTitle = trim($input['section_title'] ?? '');
    $autorNombre = trim($input['autor_nombre'] ?? '') ?: 'Tres Puntos';

    if ($texto === '' || mb_strlen($texto) > 4000) jsonError('Texto inválido (vacío o >4000 caracteres)', 422);
    if (mb_strlen($autorNombre) > 80) jsonError('Nombre de autor demasiado largo (>80)', 422);

    // Verificar proveedor existe y activo
    $chk = $pdo->prepare("SELECT id, activo, nombre FROM propuesta_proveedores WHERE id = ?");
    $chk->execute([$proveedorId]);
    $prov = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$prov) jsonError('Proveedor no encontrado', 404);
    if ((int)$prov['activo'] === 0) jsonError('Proveedor revocado', 409);

    $pdo->prepare("INSERT INTO proveedor_mensajes
        (proveedor_id, section_anchor, section_title, autor_tipo, autor_nombre, texto, parent_id, is_draft)
        VALUES (?, ?, ?, 'staff', ?, ?, NULL, ?)")
        ->execute([
            $proveedorId,
            $sectionAnchor !== '' ? $sectionAnchor : null,
            $sectionTitle !== '' ? $sectionTitle : null,
            $autorNombre,
            $texto,
            $isDraft ? 1 : 0,
        ]);
    $newId = (int)$pdo->lastInsertId();

    if (!$isDraft) {
        $resumen = mb_substr($texto, 0, 120) . (mb_strlen($texto) > 120 ? '…' : '');
        $sectionLabel = $sectionTitle ?: ($sectionAnchor ?: 'general');
        notifyTelegram("📩 Nuevo hilo TP→proveedor #{$proveedorId} (" . htmlspecialchars($prov['nombre']) . ") · <i>" . htmlspecialchars($sectionLabel) . "</i>\n" . htmlspecialchars($resumen));
    }

    jsonSuccess([
        'id' => $newId,
        'is_draft' => $isDraft,
        'autor_nombre' => $autorNombre,
        'proveedor_id' => $proveedorId,
    ]);
}

function handleProviderPublishReply(PDO $pdo, int $replyId): void {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $texto = isset($input['texto']) ? trim($input['texto']) : null;

    $row = $pdo->prepare("SELECT autor_tipo, is_draft, texto, section_anchor, section_title FROM proveedor_mensajes WHERE id = ?");
    $row->execute([$replyId]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if (!$r) jsonError('Reply no encontrado', 404);
    if ($r['autor_tipo'] !== 'staff') jsonError('Solo se publican respuestas staff', 422);
    if ((int)$r['is_draft'] !== 1) jsonError('La respuesta ya está publicada', 409);

    $textoFinal = $texto !== null && $texto !== '' ? $texto : $r['texto'];
    if (mb_strlen($textoFinal) > 4000) jsonError('Texto demasiado largo', 422);

    $pdo->prepare("UPDATE proveedor_mensajes SET texto = ?, is_draft = 0 WHERE id = ?")->execute([$textoFinal, $replyId]);

    $resumen = mb_substr($textoFinal, 0, 120) . (mb_strlen($textoFinal) > 120 ? '…' : '');
    notifyTelegram("✅ Publicada a proveedor via API · <i>" . htmlspecialchars($r['section_title'] ?: ($r['section_anchor'] ?: 'general')) . "</i>\n" . htmlspecialchars($resumen));

    jsonSuccess(['id' => $replyId, 'published' => true]);
}

function handleProviderDiscardReply(PDO $pdo, int $replyId): void {
    $stmt = $pdo->prepare("DELETE FROM proveedor_mensajes WHERE id = ? AND autor_tipo = 'staff' AND is_draft = 1");
    $stmt->execute([$replyId]);
    if ($stmt->rowCount() === 0) jsonError('Solo se descartan borradores staff', 422);
    jsonSuccess(['discarded' => true]);
}

function handleProviderResolve(PDO $pdo, int $rootId): void {
    $row = $pdo->prepare("SELECT resuelto, parent_id, section_anchor, section_title FROM proveedor_mensajes WHERE id = ?");
    $row->execute([$rootId]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if (!$r) jsonError('Mensaje no encontrado', 404);
    if ($r['parent_id']) jsonError('Solo se cierran mensajes raíz', 422);

    $newState = (int)$r['resuelto'] === 1 ? 0 : 1;
    if ($newState === 1) {
        $pdo->prepare("UPDATE proveedor_mensajes SET resuelto = 1 WHERE id = ?")->execute([$rootId]);
        notifyTelegram("✅ Hilo proveedor cerrado via API · <i>" . htmlspecialchars($r['section_title'] ?: ($r['section_anchor'] ?: 'general')) . "</i>");
    } else {
        $pdo->prepare("UPDATE proveedor_mensajes SET resuelto = 0 WHERE id = ?")->execute([$rootId]);
        notifyTelegram("↩️ Hilo proveedor reabierto via API · <i>" . htmlspecialchars($r['section_title'] ?: ($r['section_anchor'] ?: 'general')) . "</i>");
    }
    jsonSuccess(['id' => $rootId, 'resuelto' => $newState]);
}

function handleProviderMarkNotified(PDO $pdo, int $propuestaId): void {
    $stmt = $pdo->prepare("UPDATE proveedor_mensajes
        SET notificado_at = CURRENT_TIMESTAMP
        WHERE proveedor_id IN (SELECT id FROM propuesta_proveedores WHERE propuesta_id = ?)
          AND autor_tipo = 'staff'
          AND (is_draft IS NULL OR is_draft = 0)
          AND notificado_at IS NULL");
    $stmt->execute([$propuestaId]);
    jsonSuccess(['updated' => $stmt->rowCount()]);
}

/**
 * GET /api/proposals.php?id=BUDGET_ID&action=provider_budget_download
 * Sirve el PDF del presupuesto autenticado con Bearer token.
 * Marca seen_at si todavía es NULL (cualquier descarga cuenta como "visto").
 */
function handleProviderBudgetDownload(PDO $pdo, int $budgetId): void {
    $row = $pdo->prepare("SELECT pp.archivo_path, pp.archivo_nombre, pp.archivo_mime,
                                 pp.archivo_size, pp.proveedor_id
                          FROM proveedor_presupuestos pp WHERE pp.id = ?");
    $row->execute([$budgetId]);
    $b = $row->fetch(PDO::FETCH_ASSOC);
    if (!$b) jsonError('Budget not found', 404);

    $absPath = realpath(__DIR__ . '/../' . ltrim($b['archivo_path'], '/'));
    $uploadsRoot = realpath(__DIR__ . '/../uploads/proveedores/');
    if (!$absPath || !$uploadsRoot || strpos($absPath, $uploadsRoot) !== 0 || !is_file($absPath)) {
        jsonError('Archivo no encontrado en disco', 404);
    }

    // Marca seen_at (defensivo)
    try {
        $pdo->prepare("UPDATE proveedor_presupuestos SET seen_at = CURRENT_TIMESTAMP WHERE id = ? AND seen_at IS NULL")
            ->execute([$budgetId]);
    } catch (\Throwable $_) { /* col no migrada */ }

    // Headers PDF + filename
    $mime = $b['archivo_mime'] ?: 'application/pdf';
    $filename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $b['archivo_nombre'] ?: ('budget-' . $budgetId . '.pdf'));
    // Limpiamos cualquier output buffering previo (estábamos en application/json)
    while (ob_get_level()) { ob_end_clean(); }
    header_remove('Content-Type');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($absPath));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($absPath);
    exit;
}
