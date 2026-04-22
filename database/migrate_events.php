<?php
/**
 * Migración — tabla propuesta_eventos para captura de analítica.
 *
 * Registra apertura, visión de sección, dwell, scroll depth, apertura de presupuesto,
 * intentos de firma, etc. — sin mouse tracking ni grabación de sesión.
 *
 * Uso:  php database/migrate_events.php   (o desde navegador una vez)
 * Idempotente.
 */

require __DIR__ . '/../config.php';
$pdo = getDBConnection();

$log = [];

$pdo->exec("
    CREATE TABLE IF NOT EXISTS propuesta_eventos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        propuesta_id INTEGER NOT NULL,
        sesion_id TEXT NOT NULL,
        visitor_hash TEXT,
        tipo TEXT NOT NULL,
        section_anchor TEXT,
        dwell_ms INTEGER,
        scroll_depth INTEGER,
        meta TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(propuesta_id) REFERENCES propuestas(id) ON DELETE CASCADE
    )
");
$log[] = "+ tabla propuesta_eventos (IF NOT EXISTS)";

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_prop_ts ON propuesta_eventos(propuesta_id, created_at DESC)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_sesion ON propuesta_eventos(sesion_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_visitor ON propuesta_eventos(propuesta_id, visitor_hash)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_tipo ON propuesta_eventos(propuesta_id, tipo)");
$log[] = "+ 4 índices";

$isCli = php_sapi_name() === 'cli';
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
echo "Migración eventos aplicada:\n" . implode("\n", $log) . "\n";
