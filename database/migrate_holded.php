<?php
/**
 * Migración — vínculo con presupuestos de Holded.
 * Idempotente. Ejecutar:
 *   php database/migrate_holded.php     (CLI)
 *   o abrir /database/migrate_holded.php una sola vez.
 */

require __DIR__ . '/../config.php';
$pdo = getDBConnection();

function tableExists(PDO $pdo, string $t): bool {
    $s = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name = " . $pdo->quote($t));
    return $s && $s->fetchColumn() !== false;
}
function colExists(PDO $pdo, string $t, string $c): bool {
    foreach ($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC) as $r) if ($r['name'] === $c) return true;
    return false;
}

$log = [];

// 1. Tabla principal: un vínculo (1:1) propuesta → presupuesto Holded.
//    La propuesta puede tener 0 o 1 presupuesto vinculado. Si se re-vincula con
//    otro docNumber, registramos histórico en presupuestos_holded_history.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS presupuestos_holded (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        propuesta_id INTEGER NOT NULL UNIQUE,
        holded_id TEXT NOT NULL,
        holded_doc_number TEXT NOT NULL,
        holded_json TEXT NOT NULL,
        synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        estado TEXT DEFAULT 'vinculado',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(propuesta_id) REFERENCES propuestas(id) ON DELETE CASCADE
    )
");
$log[] = "+ presupuestos_holded (IF NOT EXISTS)";
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_preshol_prop ON presupuestos_holded(propuesta_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_preshol_doc ON presupuestos_holded(holded_doc_number)");
$log[] = "+ índices";

// 2. Histórico: cada vez que se vincula/re-vincula/desvincula se archiva el JSON.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS presupuestos_holded_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        propuesta_id INTEGER NOT NULL,
        holded_id TEXT,
        holded_doc_number TEXT,
        holded_json TEXT,
        accion TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");
$log[] = "+ presupuestos_holded_history (IF NOT EXISTS)";

$isCli = php_sapi_name() === 'cli';
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
echo "Migración Holded aplicada:\n" . implode("\n", $log) . "\n";
