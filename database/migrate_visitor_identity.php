<?php
/**
 * Migración — columnas visitor_name / visitor_email / is_internal en propuesta_eventos.
 *
 * Desde abril 2026 el acceso a /p/{slug} y /s/{token} pide identidad upfront
 * (nombre + email además del PIN). Cada evento queda atribuido a una persona concreta
 * en lugar del hash anónimo IP+UA.
 *
 * Además is_internal=1 marca eventos del propio equipo (emails en INTERNAL_EMAILS)
 * para que admin_analytics filtre sin contaminar stats del cliente.
 *
 * Uso:  php database/migrate_visitor_identity.php   (o HTTPS en prod, y borrar)
 * Idempotente.
 */

require __DIR__ . '/../config.php';
$pdo = getDBConnection();
$isCli = php_sapi_name() === 'cli';
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');

$log = [];

// --- Añadir columnas si no existen ---
$cols = $pdo->query("PRAGMA table_info(propuesta_eventos)")->fetchAll(PDO::FETCH_ASSOC);
$existing = array_column($cols, 'name');

$toAdd = [
    'visitor_name'  => 'TEXT',
    'visitor_email' => 'TEXT',
    'is_internal'   => 'INTEGER DEFAULT 0',
];

foreach ($toAdd as $col => $type) {
    if (!in_array($col, $existing, true)) {
        $pdo->exec("ALTER TABLE propuesta_eventos ADD COLUMN $col $type");
        $log[] = "+ columna propuesta_eventos.$col ($type)";
    } else {
        $log[] = "· columna propuesta_eventos.$col ya existe";
    }
}

// --- Índice para filtrar rápido por email / no-internos ---
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_email ON propuesta_eventos(propuesta_id, visitor_email)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_noninternal ON propuesta_eventos(propuesta_id, is_internal)");
$log[] = "+ índices visitor_email / is_internal";

echo "Migración visitor_identity aplicada:\n" . implode("\n", $log) . "\n";
