<?php
/**
 * Migración idempotente · proveedor_presupuestos · seen_at
 *
 * Añade columna seen_at DATETIME NULL para marcar cuándo el admin ha
 * visto un presupuesto subido por proveedor. NULL = no visto, badge en sidebar.
 *
 * Uso: subir a /doc/database/, ejecutar via HTTPS, borrar.
 *      php database/migrate_presupuestos_seen.php (en local)
 */
require __DIR__ . '/../config.php';
$pdo = getDBConnection();

$out = [];

// Verifica si existe la tabla
$tab = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='proveedor_presupuestos'")->fetchColumn();
if (!$tab) {
    $out[] = '⚠ proveedor_presupuestos no existe — ejecuta migrate_providers.php primero';
} else {
    $cols = $pdo->query("PRAGMA table_info(proveedor_presupuestos)")->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($cols, 'name');
    if (in_array('seen_at', $names, true)) {
        $out[] = '✓ seen_at ya existe (idempotente)';
    } else {
        $pdo->exec("ALTER TABLE proveedor_presupuestos ADD COLUMN seen_at DATETIME NULL");
        $out[] = '+ seen_at DATETIME NULL añadida';
    }
    // Índice para sidebar (optimiza WHERE seen_at IS NULL)
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pres_seen ON proveedor_presupuestos(seen_at)");
    $out[] = '+ idx_pres_seen';

    // Stats
    $total = $pdo->query("SELECT COUNT(*) FROM proveedor_presupuestos")->fetchColumn();
    $unseen = $pdo->query("SELECT COUNT(*) FROM proveedor_presupuestos WHERE seen_at IS NULL")->fetchColumn();
    $out[] = "Total presupuestos: {$total} · sin ver: {$unseen}";
}

$cli = php_sapi_name() === 'cli';
if (!$cli) header('Content-Type: text/plain; charset=utf-8');
echo "Migración seen_at:\n" . implode("\n", $out) . "\n";
