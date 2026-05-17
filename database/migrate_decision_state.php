<?php
/**
 * Migración · Sistema de estados de presupuesto de proveedor
 *
 * Añade a `proveedor_presupuestos`:
 *   - seen_at         (compatibilidad con código actual del sidebar)
 *   - decision_state  (recibido | en_revision | aceptado | rechazado | iteracion_solicitada)
 *   - decision_at     (cuándo se cambió por última vez de 'recibido')
 *   - decision_by     (email del admin que decidió)
 *   - decision_note   (última nota textual del cambio)
 *
 * Crea tabla `proveedor_presupuestos_eventos` con audit trail completo
 * de cada cambio de estado.
 *
 * Idempotente: se puede ejecutar N veces sin romper nada.
 */

require __DIR__ . '/../config.php';

$pdo = getDBConnection();
$out = [];

function add_column_if_missing(PDO $pdo, string $table, string $column, string $type, &$out): void {
    $stmt = $pdo->query("PRAGMA table_info({$table})");
    $cols = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (in_array($column, $cols, true)) {
        $out[] = "  · columna '{$column}' ya existe — skip";
        return;
    }
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$type}");
    $out[] = "  ✓ añadida columna '{$column}' ({$type})";
}

$out[] = "→ proveedor_presupuestos · columnas nuevas";

// seen_at: ya existe en prod, en local puede faltar
add_column_if_missing($pdo, 'proveedor_presupuestos', 'seen_at', 'DATETIME', $out);

// Máquina de estados
add_column_if_missing($pdo, 'proveedor_presupuestos', 'decision_state', "TEXT NOT NULL DEFAULT 'recibido'", $out);
add_column_if_missing($pdo, 'proveedor_presupuestos', 'decision_at', 'DATETIME', $out);
add_column_if_missing($pdo, 'proveedor_presupuestos', 'decision_by', 'TEXT', $out);
add_column_if_missing($pdo, 'proveedor_presupuestos', 'decision_note', 'TEXT', $out);

// Asegurar que todas las filas existentes tengan estado 'recibido'
// (defensivo: si ALTER añadió con default, ya están; si no, esto las pone)
$pdo->exec("UPDATE proveedor_presupuestos SET decision_state = 'recibido' WHERE decision_state IS NULL OR decision_state = ''");

// Índice por estado para queries rápidas en dashboard ("¿hay alguno pendiente?")
try {
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pres_state ON proveedor_presupuestos(decision_state)");
    $out[] = "  ✓ índice idx_pres_state OK";
} catch (\Throwable $e) {
    $out[] = "  ! índice no creado: " . $e->getMessage();
}

// ─── Tabla de eventos · audit trail ──────────────────────────────
$out[] = "→ proveedor_presupuestos_eventos · audit trail";

$pdo->exec("
CREATE TABLE IF NOT EXISTS proveedor_presupuestos_eventos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    presupuesto_id INTEGER NOT NULL,
    from_state TEXT,
    to_state TEXT NOT NULL,
    note TEXT,
    autor_email TEXT,
    autor_nombre TEXT,
    notified_provider INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(presupuesto_id) REFERENCES proveedor_presupuestos(id) ON DELETE CASCADE
)
");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_pres_events_pres ON proveedor_presupuestos_eventos(presupuesto_id)");
$out[] = "  ✓ tabla y índice OK";

// Sanity check final: cuántos presupuestos hay y en qué estado
$counts = $pdo->query("SELECT decision_state, COUNT(*) as n FROM proveedor_presupuestos GROUP BY decision_state")
              ->fetchAll(PDO::FETCH_ASSOC);
$out[] = "→ Estado actual de los presupuestos en BD:";
if (!$counts) {
    $out[] = "  (sin filas)";
} else {
    foreach ($counts as $c) {
        $out[] = "  · " . $c['decision_state'] . ": " . $c['n'];
    }
}

// Output
if (php_sapi_name() === 'cli') {
    echo "Migración decision_state\n";
    echo str_repeat('─', 50) . "\n";
    foreach ($out as $line) echo $line . "\n";
    echo str_repeat('─', 50) . "\n";
    echo "Done.\n";
} else {
    echo '<pre style="font-family:monospace;background:#0e0e0e;color:#f5f5f5;padding:1rem;border-radius:6px;">';
    echo "Migración decision_state\n";
    echo str_repeat('─', 50) . "\n";
    foreach ($out as $line) echo htmlspecialchars($line) . "\n";
    echo str_repeat('─', 50) . "\n";
    echo "Done.\n";
    echo '</pre>';
}
