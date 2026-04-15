<?php
/**
 * Migración — Jordan-doc:
 *  - Flag `enable_ai_assistant` en propuestas (default 1, ON por defecto).
 *  - Tabla `jordan_conversaciones` para el histórico de chat.
 *
 * Idempotente.
 */

require __DIR__ . '/../config.php';
$pdo = getDBConnection();

function colExists(PDO $pdo, string $table, string $col): bool {
    $rows = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) if ($r['name'] === $col) return true;
    return false;
}

$log = [];

// Default 0: Jordan OFF por defecto. Se activa desde el admin por propuesta.
if (!colExists($pdo, 'propuestas', 'enable_ai_assistant')) {
    $pdo->exec("ALTER TABLE propuestas ADD COLUMN enable_ai_assistant INTEGER DEFAULT 0");
    $log[] = "+ propuestas.enable_ai_assistant (default 0)";
} else {
    $log[] = "= propuestas.enable_ai_assistant ya existe";
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS jordan_conversaciones (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        propuesta_id INTEGER NOT NULL,
        session_id TEXT NOT NULL,
        role TEXT NOT NULL,             -- 'user' o 'assistant'
        content TEXT NOT NULL,
        tokens_in INTEGER DEFAULT 0,
        tokens_out INTEGER DEFAULT 0,
        tokens_cache_read INTEGER DEFAULT 0,
        tokens_cache_create INTEGER DEFAULT 0,
        ip_address TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(propuesta_id) REFERENCES propuestas(id) ON DELETE CASCADE
    )
");
$log[] = "+ jordan_conversaciones (IF NOT EXISTS)";

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_jordan_prop ON jordan_conversaciones(propuesta_id, session_id, created_at)");
$log[] = "+ índice jordan_conversaciones";

$isCli = php_sapi_name() === 'cli';
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
echo "Migración Jordan-doc aplicada:\n" . implode("\n", $log) . "\n";
