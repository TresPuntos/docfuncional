<?php
/**
 * Migración — respuestas de staff + auditoría de cierre.
 *
 * Añade a `comentarios_seccion`:
 *   is_staff      INTEGER DEFAULT 0   — 1 si la respuesta la dejó Tres Puntos
 *   resuelto_por  TEXT                — "Nombre Apellidos" de quien cerró
 *   resuelto_at   DATETIME            — cuándo se cerró
 *
 * Uso:  php database/migrate_staff_replies.php
 * Idempotente.
 */

require __DIR__ . '/../config.php';
$pdo = getDBConnection();

function colExists(PDO $pdo, string $table, string $col): bool {
    foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['name'] === $col) return true;
    }
    return false;
}

$log = [];
foreach ([
    'is_staff' => 'INTEGER DEFAULT 0',
    'resuelto_por' => 'TEXT',
    'resuelto_at' => 'DATETIME',
] as $col => $type) {
    if (!colExists($pdo, 'comentarios_seccion', $col)) {
        $pdo->exec("ALTER TABLE comentarios_seccion ADD COLUMN $col $type");
        $log[] = "+ comentarios_seccion.$col";
    } else {
        $log[] = "= comentarios_seccion.$col ya existe";
    }
}

$isCli = php_sapi_name() === 'cli';
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
echo "Migración staff replies aplicada:\n" . implode("\n", $log) . "\n";
