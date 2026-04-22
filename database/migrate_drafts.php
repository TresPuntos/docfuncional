<?php
/**
 * Migración — borradores de respuesta staff + marca de notificación al cliente.
 *
 * Añade a `comentarios_seccion`:
 *   is_draft       INTEGER DEFAULT 0   — 1 mientras la respuesta está sin publicar (el cliente no la ve)
 *   notificado_at  DATETIME            — cuándo se avisó al cliente por email (NULL = pendiente)
 *
 * Uso:  php database/migrate_drafts.php
 * Idempotente.
 */

require __DIR__ . '/../config.php';
$pdo = getDBConnection();

function colExists2(PDO $pdo, string $table, string $col): bool {
    foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['name'] === $col) return true;
    }
    return false;
}

$log = [];
foreach ([
    'is_draft' => 'INTEGER DEFAULT 0',
    'notificado_at' => 'DATETIME',
] as $col => $type) {
    if (!colExists2($pdo, 'comentarios_seccion', $col)) {
        $pdo->exec("ALTER TABLE comentarios_seccion ADD COLUMN $col $type");
        $log[] = "+ comentarios_seccion.$col";
    } else {
        $log[] = "= comentarios_seccion.$col ya existe";
    }
}

$isCli = php_sapi_name() === 'cli';
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
echo "Migración drafts aplicada:\n" . implode("\n", $log) . "\n";
