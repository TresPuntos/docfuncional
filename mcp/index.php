<?php
/**
 * Tres Puntos — MCP Server (Streamable HTTP)
 *
 * Implements the Model Context Protocol over Streamable HTTP transport.
 * Allows Claude.ai, Claude Cowork, and Claude Desktop to manage proposals
 * directly via custom connector.
 *
 * Endpoint: https://doc.trespuntos-lab.com/mcp/
 * Protocol: MCP Streamable HTTP (JSON-RPC 2.0)
 */

require_once __DIR__ . '/../config.php';

// === CORS Headers (always) ===
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Mcp-Session-Id, Authorization, Mcp-Protocol-Version');
header('Access-Control-Expose-Headers: Mcp-Session-Id');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// === Session management ===
$sessionId = $_SERVER['HTTP_MCP_SESSION_ID'] ?? null;

// DELETE = terminate session
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    header('Content-Type: application/json');
    http_response_code(200);
    echo json_encode(['jsonrpc' => '2.0', 'result' => null, 'id' => null]);
    exit;
}

// GET = Server info / SSE endpoint
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

    // SSE stream request — send server-sent events endpoint
    if (str_contains($accept, 'text/event-stream')) {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        // Send a ping event and close (stateless PHP can't keep SSE open)
        echo "event: endpoint\ndata: /mcp/\n\n";
        flush();
        exit;
    }

    // Regular GET — return server info (discovery)
    echo json_encode([
        'jsonrpc' => '2.0',
        'result' => [
            'protocolVersion' => '2025-03-26',
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'Tres Puntos Proposals', 'version' => '1.0.0'],
        ],
        'id' => null,
    ]);
    exit;
}

// Only POST from here
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

header('Content-Type: application/json');

// === Parse JSON-RPC request ===
$raw = file_get_contents('php://input');
$request = json_decode($raw, true);

if (!$request) {
    http_response_code(400);
    echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32700, 'message' => 'Parse error'], 'id' => null]);
    exit;
}

// Handle batch requests (array of requests)
if (isset($request[0])) {
    $responses = [];
    foreach ($request as $req) {
        $resp = handleRequest($req);
        if ($resp !== null) {
            $responses[] = $resp;
        }
    }
    echo json_encode($responses);
    exit;
}

// Single request
$response = handleRequest($request);
if ($response !== null) {
    echo json_encode($response);
} else {
    // Notification — no response needed
    http_response_code(202);
}
exit;

// ============================================================
// REQUEST ROUTER
// ============================================================

function handleRequest(array $request): ?array {
    $method = $request['method'] ?? '';
    $id = $request['id'] ?? null;
    $params = $request['params'] ?? [];

    // Notifications (no id) don't get responses
    $isNotification = !isset($request['id']);

    switch ($method) {
        case 'initialize':
            $sessionId = bin2hex(random_bytes(16));
            header('Mcp-Session-Id: ' . $sessionId);
            return jsonRpcResult($id, [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [
                    'tools' => ['listChanged' => false],
                ],
                'serverInfo' => [
                    'name' => 'Tres Puntos Proposals',
                    'version' => '1.0.0',
                ],
                'instructions' => 'This server manages proposals and functional documents for Tres Puntos, a UX/UI and web development agency. You can list, read, create, update, and restore versions of client proposals. Each proposal contains styled HTML that clients view via a PIN-protected URL. Always read an existing proposal first (get_proposal) to understand the HTML format before creating new ones.',
            ]);

        case 'notifications/initialized':
            return null; // No response for notifications

        case 'tools/list':
            return jsonRpcResult($id, ['tools' => getToolDefinitions()]);

        case 'tools/call':
            return handleToolCall($id, $params);

        case 'ping':
            return jsonRpcResult($id, []);

        default:
            if ($isNotification) return null;
            return jsonRpcError($id, -32601, "Method not found: $method");
    }
}

// ============================================================
// TOOL DEFINITIONS
// ============================================================

function getToolDefinitions(): array {
    return [
        [
            'name' => 'list_proposals',
            'description' => 'List all proposals with their id, slug, client name, status, version, views, and approval state.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
            ],
        ],
        [
            'name' => 'get_proposal',
            'description' => 'Get the full detail of a proposal including HTML content, team members, approvals, and feedback. Use this to understand the HTML format before creating new proposals.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'proposal_id' => ['type' => 'integer', 'description' => 'ID of the proposal'],
                ],
                'required' => ['proposal_id'],
            ],
        ],
        [
            'name' => 'create_proposal',
            'description' => 'Create a new proposal/functional document.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'slug' => ['type' => 'string', 'description' => 'URL slug (auto-sanitized, auto-incremented if duplicate)'],
                    'client_name' => ['type' => 'string', 'description' => 'Client name'],
                    'pin' => ['type' => 'string', 'description' => '4-digit PIN for client access'],
                    'html_content' => ['type' => 'string', 'description' => 'Full HTML content of the functional document'],
                    'version' => ['type' => 'string', 'description' => 'Version label (default: v1.0)'],
                    'sent_date' => ['type' => 'string', 'description' => 'Send date YYYY-MM-DD (optional)'],
                    'equipo_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Team member IDs (use get_team to see available)'],
                ],
                'required' => ['slug', 'client_name', 'pin', 'html_content'],
            ],
        ],
        [
            'name' => 'update_proposal',
            'description' => 'Update a proposal (draft mode — does NOT save previous version to history). Use for intermediate adjustments like text fixes or CSS tweaks. Only send fields you want to change.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'proposal_id' => ['type' => 'integer', 'description' => 'ID of the proposal to update'],
                    'html_content' => ['type' => 'string', 'description' => 'New HTML content'],
                    'client_name' => ['type' => 'string', 'description' => 'New client name'],
                    'slug' => ['type' => 'string', 'description' => 'New URL slug'],
                    'pin' => ['type' => 'string', 'description' => 'New PIN'],
                    'version' => ['type' => 'string', 'description' => 'New version label'],
                    'sent_date' => ['type' => 'string', 'description' => 'Send date YYYY-MM-DD'],
                    'equipo_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Team member IDs'],
                ],
                'required' => ['proposal_id'],
            ],
        ],
        [
            'name' => 'save_new_version',
            'description' => 'Update a proposal AND save the current version to history. Use ONLY when the document is finalized and you want to create an official new version (v1.1, v2.0, etc). Do NOT use for intermediate adjustments.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'proposal_id' => ['type' => 'integer', 'description' => 'ID of the proposal'],
                    'html_content' => ['type' => 'string', 'description' => 'New HTML content'],
                    'version' => ['type' => 'string', 'description' => 'New version label (e.g. v2.0)'],
                    'sent_date' => ['type' => 'string', 'description' => 'Send date YYYY-MM-DD (optional)'],
                ],
                'required' => ['proposal_id', 'html_content', 'version'],
            ],
        ],
        [
            'name' => 'restore_version',
            'description' => 'Restore a previous version from history. The current version is saved to history before restoring, so nothing is ever lost. Use get_history first to see available versions.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'proposal_id' => ['type' => 'integer', 'description' => 'ID of the proposal'],
                    'history_id' => ['type' => 'integer', 'description' => 'History ID to restore (from get_history)'],
                    'version' => ['type' => 'string', 'description' => 'Version label to restore (alternative to history_id)'],
                ],
                'required' => ['proposal_id'],
            ],
        ],
        [
            'name' => 'get_history',
            'description' => 'Get version history of a proposal. Shows saved versions with IDs, labels, and dates.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'proposal_id' => ['type' => 'integer', 'description' => 'ID of the proposal'],
                ],
                'required' => ['proposal_id'],
            ],
        ],
        [
            'name' => 'get_team',
            'description' => 'List all available team members with their IDs, names, and roles. Use their IDs when creating or updating proposals.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
            ],
        ],
    ];
}

// ============================================================
// TOOL EXECUTION
// ============================================================

function handleToolCall(?string $id, array $params): array {
    $toolName = $params['name'] ?? '';
    $args = $params['arguments'] ?? [];

    try {
        $pdo = getDBConnection();
    } catch (Exception $e) {
        return jsonRpcResult($id, ['content' => [['type' => 'text', 'text' => 'Database connection failed.']], 'isError' => true]);
    }

    switch ($toolName) {
        case 'list_proposals':
            return toolResult($id, toolListProposals($pdo));
        case 'get_proposal':
            return toolResult($id, toolGetProposal($pdo, $args));
        case 'create_proposal':
            return toolResult($id, toolCreateProposal($pdo, $args));
        case 'update_proposal':
            return toolResult($id, toolUpdateProposal($pdo, $args));
        case 'save_new_version':
            return toolResult($id, toolSaveNewVersion($pdo, $args));
        case 'restore_version':
            return toolResult($id, toolRestoreVersion($pdo, $args));
        case 'get_history':
            return toolResult($id, toolGetHistory($pdo, $args));
        case 'get_team':
            return toolResult($id, toolGetTeam($pdo));
        default:
            return toolResult($id, "Unknown tool: $toolName", true);
    }
}

// ============================================================
// TOOL IMPLEMENTATIONS (direct DB access, no HTTP overhead)
// ============================================================

function toolListProposals(PDO $pdo): string {
    $stmt = $pdo->query("
        SELECT p.id, p.slug, p.client_name, p.status, p.version,
               p.created_at, p.sent_date, p.views_count
        FROM propuestas p ORDER BY p.created_at DESC
    ");
    $proposals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $appStmt = $pdo->query("SELECT propuesta_id, tipo, aprobado_at FROM aprobaciones");
    $approvals = [];
    foreach ($appStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $approvals[$a['propuesta_id']][$a['tipo']] = $a['aprobado_at'];
    }

    $lines = [];
    foreach ($proposals as $p) {
        $status = $p['status'] == 1 ? 'active' : 'inactive';
        $app = $approvals[$p['id']] ?? [];
        $appStr = $app ? implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($app), $app)) : 'none';
        $lines[] = "ID:{$p['id']} | {$p['client_name']} | slug:{$p['slug']} | $status | {$p['version']} | views:{$p['views_count']} | approvals: $appStr";
    }
    return implode("\n", $lines) ?: 'No proposals found.';
}

function toolGetProposal(PDO $pdo, array $args): string {
    $id = (int)($args['proposal_id'] ?? 0);
    if (!$id) return 'Error: proposal_id is required.';

    $stmt = $pdo->prepare("SELECT * FROM propuestas WHERE id = ?");
    $stmt->execute([$id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) return 'Proposal not found.';

    // Approvals
    $appStmt = $pdo->prepare("SELECT tipo, aprobado_at FROM aprobaciones WHERE propuesta_id = ?");
    $appStmt->execute([$id]);
    $approvals = $appStmt->fetchAll(PDO::FETCH_ASSOC);
    $appStr = $approvals ? implode(', ', array_map(fn($a) => "{$a['tipo']}: {$a['aprobado_at']}", $approvals)) : 'none';

    // Team
    $equipoIds = json_decode($p['equipo_ids'] ?? '[]', true);
    $teamStr = 'none assigned';
    if (!empty($equipoIds)) {
        $ph = implode(',', array_fill(0, count($equipoIds), '?'));
        $eqStmt = $pdo->prepare("SELECT nombre, cargo FROM equipo WHERE id IN ($ph) ORDER BY orden");
        $eqStmt->execute($equipoIds);
        $members = $eqStmt->fetchAll(PDO::FETCH_ASSOC);
        $teamStr = implode(', ', array_map(fn($m) => "{$m['nombre']} ({$m['cargo']})", $members));
    }

    $status = $p['status'] == 1 ? 'active' : 'inactive';
    return "ID: {$p['id']}\nClient: {$p['client_name']}\nSlug: {$p['slug']}\nVersion: {$p['version']}\nPIN: {$p['pin']}\nStatus: $status\nViews: {$p['views_count']}\nSent date: " . ($p['sent_date'] ?? 'not set') . "\nCreated: {$p['created_at']}\nTeam: $teamStr\nApprovals: $appStr\nURL: https://doc.trespuntos-lab.com/p/{$p['slug']}\n\n--- HTML CONTENT ---\n{$p['html_content']}";
}

function toolCreateProposal(PDO $pdo, array $args): string {
    foreach (['slug', 'client_name', 'pin', 'html_content'] as $f) {
        if (empty($args[$f])) return "Error: $f is required.";
    }

    $slug = preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower(trim($args['slug'])));
    $clientName = trim($args['client_name']);
    $pin = trim($args['pin']);
    $html = $args['html_content'];
    $version = trim($args['version'] ?? 'v1.0');
    $sentDate = !empty($args['sent_date']) ? trim($args['sent_date']) : null;
    $equipoIds = isset($args['equipo_ids']) && is_array($args['equipo_ids'])
        ? json_encode(array_map('intval', $args['equipo_ids'])) : '[]';

    // Auto-increment slug
    $origSlug = $slug;
    $counter = 1;
    while (true) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM propuestas WHERE slug = ?");
        $check->execute([$slug]);
        if ($check->fetchColumn() == 0) break;
        $slug = $origSlug . '-' . $counter++;
    }

    $stmt = $pdo->prepare("INSERT INTO propuestas (slug, client_name, pin, html_content, sent_date, version, equipo_ids) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$slug, $clientName, $pin, $html, $sentDate, $version, $equipoIds]);
    $newId = $pdo->lastInsertId();

    // Telegram notification
    notifyTelegram("📄 Nueva propuesta via MCP\n\nCliente: $clientName\nSlug: $slug\nID: $newId");

    return "Proposal created!\nID: $newId\nSlug: $slug\nVersion: $version\nURL: https://doc.trespuntos-lab.com/p/$slug";
}

function toolUpdateProposal(PDO $pdo, array $args): string {
    $id = (int)($args['proposal_id'] ?? 0);
    if (!$id) return 'Error: proposal_id is required.';

    $stmt = $pdo->prepare("SELECT * FROM propuestas WHERE id = ?");
    $stmt->execute([$id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$current) return 'Proposal not found.';

    $slug = isset($args['slug']) ? preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower(trim($args['slug']))) : $current['slug'];
    $clientName = isset($args['client_name']) ? trim($args['client_name']) : $current['client_name'];
    $pin = isset($args['pin']) ? trim($args['pin']) : $current['pin'];
    $html = $args['html_content'] ?? $current['html_content'];
    $sentDate = array_key_exists('sent_date', $args) ? ($args['sent_date'] ?: null) : $current['sent_date'];
    $version = isset($args['version']) ? trim($args['version']) : $current['version'];
    $equipoIds = isset($args['equipo_ids']) && is_array($args['equipo_ids'])
        ? json_encode(array_map('intval', $args['equipo_ids'])) : $current['equipo_ids'];

    if ($slug !== $current['slug']) {
        $origSlug = $slug;
        $counter = 1;
        while (true) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM propuestas WHERE slug = ? AND id != ?");
            $check->execute([$slug, $id]);
            if ($check->fetchColumn() == 0) break;
            $slug = $origSlug . '-' . $counter++;
        }
    }

    $upd = $pdo->prepare("UPDATE propuestas SET slug=?, client_name=?, pin=?, html_content=?, sent_date=?, version=?, equipo_ids=? WHERE id=?");
    $upd->execute([$slug, $clientName, $pin, $html, $sentDate, $version, $equipoIds, $id]);

    return "Updated (draft). Version: $version. No history saved.";
}

function toolSaveNewVersion(PDO $pdo, array $args): string {
    $id = (int)($args['proposal_id'] ?? 0);
    if (!$id) return 'Error: proposal_id is required.';
    if (empty($args['html_content'])) return 'Error: html_content is required.';
    if (empty($args['version'])) return 'Error: version is required.';

    $stmt = $pdo->prepare("SELECT * FROM propuestas WHERE id = ?");
    $stmt->execute([$id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$current) return 'Proposal not found.';

    // Save current to history
    $hist = $pdo->prepare("INSERT INTO propuestas_history (propuesta_id, version, html_content) VALUES (?, ?, ?)");
    $hist->execute([$id, $current['version'], $current['html_content']]);

    // Update
    $version = trim($args['version']);
    $sentDate = !empty($args['sent_date']) ? trim($args['sent_date']) : $current['sent_date'];
    $upd = $pdo->prepare("UPDATE propuestas SET html_content=?, version=?, sent_date=? WHERE id=?");
    $upd->execute([$args['html_content'], $version, $sentDate, $id]);

    return "New version saved!\nVersion: $version\nPrevious version ({$current['version']}) archived in history.";
}

function toolRestoreVersion(PDO $pdo, array $args): string {
    $id = (int)($args['proposal_id'] ?? 0);
    if (!$id) return 'Error: proposal_id is required.';

    $stmt = $pdo->prepare("SELECT * FROM propuestas WHERE id = ?");
    $stmt->execute([$id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$current) return 'Proposal not found.';

    // Find version to restore
    if (!empty($args['history_id'])) {
        $hStmt = $pdo->prepare("SELECT id, version, html_content FROM propuestas_history WHERE id = ? AND propuesta_id = ?");
        $hStmt->execute([(int)$args['history_id'], $id]);
    } elseif (!empty($args['version'])) {
        $hStmt = $pdo->prepare("SELECT id, version, html_content FROM propuestas_history WHERE version = ? AND propuesta_id = ? ORDER BY created_at DESC LIMIT 1");
        $hStmt->execute([trim($args['version']), $id]);
    } else {
        return 'Error: Provide history_id or version to restore.';
    }

    $histRecord = $hStmt->fetch(PDO::FETCH_ASSOC);
    if (!$histRecord) return 'Version not found in history.';

    // Save current before restoring
    $save = $pdo->prepare("INSERT INTO propuestas_history (propuesta_id, version, html_content) VALUES (?, ?, ?)");
    $save->execute([$id, $current['version'], $current['html_content']]);

    // Restore
    $upd = $pdo->prepare("UPDATE propuestas SET html_content=?, version=? WHERE id=?");
    $upd->execute([$histRecord['html_content'], $histRecord['version'], $id]);

    return "Restored to {$histRecord['version']}.\nPrevious version ({$current['version']}) saved to history.";
}

function toolGetHistory(PDO $pdo, array $args): string {
    $id = (int)($args['proposal_id'] ?? 0);
    if (!$id) return 'Error: proposal_id is required.';

    $check = $pdo->prepare("SELECT id FROM propuestas WHERE id = ?");
    $check->execute([$id]);
    if (!$check->fetch()) return 'Proposal not found.';

    $stmt = $pdo->prepare("SELECT id, version, created_at, LENGTH(html_content) as content_length FROM propuestas_history WHERE propuesta_id = ? ORDER BY created_at DESC");
    $stmt->execute([$id]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($history)) return 'No version history found.';

    $lines = [];
    foreach ($history as $h) {
        $lines[] = "History ID:{$h['id']} | {$h['version']} | {$h['created_at']} | {$h['content_length']} chars";
    }
    return implode("\n", $lines);
}

function toolGetTeam(PDO $pdo): string {
    $stmt = $pdo->query("SELECT id, nombre, cargo, descripcion FROM equipo ORDER BY orden");
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($members)) return 'No team members found.';

    $lines = [];
    foreach ($members as $m) {
        $lines[] = "ID:{$m['id']} | {$m['nombre']} | {$m['cargo']} | {$m['descripcion']}";
    }
    return implode("\n", $lines);
}

// ============================================================
// HELPERS
// ============================================================

function jsonRpcResult(?string $id, $result): array {
    return ['jsonrpc' => '2.0', 'result' => $result, 'id' => $id];
}

function jsonRpcError(?string $id, int $code, string $message): array {
    return ['jsonrpc' => '2.0', 'error' => ['code' => $code, 'message' => $message], 'id' => $id];
}

function toolResult(?string $id, string $text, bool $isError = false): array {
    return jsonRpcResult($id, [
        'content' => [['type' => 'text', 'text' => $text]],
        'isError' => $isError,
    ]);
}

function notifyTelegram(string $text): void {
    if (!defined('TELEGRAM_BOT_TOKEN') || !defined('TELEGRAM_CHAT_ID')) return;
    $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['chat_id' => TELEGRAM_CHAT_ID, 'text' => $text, 'parse_mode' => 'HTML']),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
