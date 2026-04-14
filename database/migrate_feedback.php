<?php
/**
 * Migración — feedback por secciones + firma ligera en aprobaciones.
 *
 * Uso:  php database/migrate_feedback.php   (CLI)
 *   o:  abrir /database/migrate_feedback.php una sola vez desde el navegador.
 *
 * Idempotente: se puede ejecutar varias veces sin romper nada.
 */

require __DIR__ . '/../config.php';
$pdo = getDBConnection();

function colExists(PDO $pdo, string $table, string $col): bool {
    $rows = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) if ($r['name'] === $col) return true;
    return false;
}

$log = [];

// 1. Ampliar aprobaciones con campos de firma
foreach ([
    'firmante_nombre' => 'TEXT',
    'firmante_apellidos' => 'TEXT',
    'firmante_email' => 'TEXT',
    'firma_hash' => 'TEXT',
    'version_firmada' => 'TEXT',
    'user_agent' => 'TEXT',
] as $col => $type) {
    if (!colExists($pdo, 'aprobaciones', $col)) {
        $pdo->exec("ALTER TABLE aprobaciones ADD COLUMN $col $type");
        $log[] = "+ aprobaciones.$col";
    } else {
        $log[] = "= aprobaciones.$col ya existe";
    }
}

// 2. Tabla nueva: comentarios por sección (con firma mínima nombre+apellidos)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS comentarios_seccion (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        propuesta_id INTEGER NOT NULL,
        section_anchor TEXT NOT NULL,
        section_title TEXT,
        autor_nombre TEXT NOT NULL,
        autor_apellidos TEXT NOT NULL,
        autor_email TEXT,
        texto TEXT NOT NULL,
        parent_id INTEGER,
        resuelto INTEGER DEFAULT 0,
        ip_address TEXT,
        user_agent TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(propuesta_id) REFERENCES propuestas(id) ON DELETE CASCADE,
        FOREIGN KEY(parent_id) REFERENCES comentarios_seccion(id) ON DELETE CASCADE
    )
");
$log[] = "+ comentarios_seccion (IF NOT EXISTS)";

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_comseccion_prop ON comentarios_seccion(propuesta_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_comseccion_anchor ON comentarios_seccion(propuesta_id, section_anchor)");
$log[] = "+ índices comentarios_seccion";

// Output
$isCli = php_sapi_name() === 'cli';
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
echo "Migración feedback aplicada:\n" . implode("\n", $log) . "\n";
