<?php
/**
 * Migración — añade `signing_token` a contratos para firmantes cliente.
 * Permite URL pública /sign.php?token=XXX accesible sin PIN.
 * Idempotente.
 */
require __DIR__ . '/../config.php';
$pdo = getDBConnection();

// Guard: si la tabla 'contratos' no existe, avisar y abortar (orden de migración)
$exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='contratos'")->fetchColumn();
if (!$exists) {
    echo "⚠ La tabla 'contratos' no existe. Ejecuta primero database/migrate_contratos.php\n";
    exit(1);
}

// Añadir columna si no existe
$cols = $pdo->query("PRAGMA table_info(contratos)")->fetchAll(PDO::FETCH_ASSOC);
$has = false;
foreach ($cols as $c) if ($c['name'] === 'signing_token') { $has = true; break; }
if (!$has) {
    $pdo->exec("ALTER TABLE contratos ADD COLUMN signing_token TEXT");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_ctr_signing_token ON contratos(signing_token)");
    echo "+ columna signing_token añadida\n";
} else {
    echo "= signing_token ya existe\n";
}

// Backfill para contratos existentes
$rows = $pdo->query("SELECT id FROM contratos WHERE signing_token IS NULL OR signing_token = ''")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $tok = bin2hex(random_bytes(16));
    $pdo->prepare("UPDATE contratos SET signing_token = ? WHERE id = ?")->execute([$tok, $r['id']]);
    echo "  · contrato id={$r['id']} → token " . substr($tok, 0, 8) . "…\n";
}
echo "Backfill completado (" . count($rows) . " contratos).\n";
