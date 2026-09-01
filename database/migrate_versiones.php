<?php
/**
 * Migración — registro de versiones aprobadas (huella de contenido).
 *
 * Objetivo: que un contrato pueda remitir al documento funcional exacto que se
 * aprobó, en vez de adjuntarlo en PDF. Para eso hacen falta tres cosas:
 *   1. Una huella SHA-256 del CONTENIDO en el momento de firmar (hoy el
 *      firma_hash solo cubre los metadatos de la firma, no el documento).
 *   2. Que la versión aprobada quede congelada en propuestas_history.
 *   3. Que la aprobación apunte a esa fila concreta (history_id), porque la
 *      etiqueta de versión ("v1.2") no identifica un documento: se puede editar
 *      en borrador sin cambiar de etiqueta, y puede haber filas repetidas.
 *
 * Idempotente. Ejecutar:
 *   php database/migrate_versiones.php     (CLI)
 *   o subir a la raíz y abrir por HTTPS una sola vez (y borrar después).
 */

require __DIR__ . '/../config.php';
$pdo = getDBConnection();

function colExists(PDO $pdo, string $t, string $c): bool {
    foreach ($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['name'] === $c) return true;
    }
    return false;
}

$log = [];

// 1. Huella del contenido + vínculo a la fila congelada, en cada aprobación.
if (!colExists($pdo, 'aprobaciones', 'contenido_hash')) {
    $pdo->exec("ALTER TABLE aprobaciones ADD COLUMN contenido_hash TEXT");
    $log[] = "+ aprobaciones.contenido_hash";
} else {
    $log[] = "= aprobaciones.contenido_hash (ya existía)";
}
if (!colExists($pdo, 'aprobaciones', 'history_id')) {
    $pdo->exec("ALTER TABLE aprobaciones ADD COLUMN history_id INTEGER");
    $log[] = "+ aprobaciones.history_id";
} else {
    $log[] = "= aprobaciones.history_id (ya existía)";
}

// 2. Huella + procedencia de cada fila del historial.
//    origen: 'save_version' (archivada al subir de versión) · 'aprobacion'
//    (congelada al firmar) · 'referencia' (congelada a mano para citarla en un
//    contrato) · 'restore' (copia de seguridad previa a restaurar).
if (!colExists($pdo, 'propuestas_history', 'contenido_hash')) {
    $pdo->exec("ALTER TABLE propuestas_history ADD COLUMN contenido_hash TEXT");
    $log[] = "+ propuestas_history.contenido_hash";
} else {
    $log[] = "= propuestas_history.contenido_hash (ya existía)";
}
if (!colExists($pdo, 'propuestas_history', 'origen')) {
    $pdo->exec("ALTER TABLE propuestas_history ADD COLUMN origen TEXT");
    $log[] = "+ propuestas_history.origen";
} else {
    $log[] = "= propuestas_history.origen (ya existía)";
}

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_hist_prop_hash ON propuestas_history(propuesta_id, contenido_hash)");
$log[] = "+ índice idx_hist_prop_hash";

// 3. Backfill: huella de todo lo que ya está archivado.
$rows = $pdo->query("SELECT id, html_content FROM propuestas_history WHERE contenido_hash IS NULL OR contenido_hash = ''")
            ->fetchAll(PDO::FETCH_ASSOC);
$upd = $pdo->prepare("UPDATE propuestas_history SET contenido_hash = ? WHERE id = ?");
foreach ($rows as $r) {
    $upd->execute([hash('sha256', (string)$r['html_content']), $r['id']]);
}
$log[] = "· backfill de huellas en historial: " . count($rows) . " filas";

// 4. Backfill de 'origen' para lo ya existente: todo lo anterior a esta
//    migración se archivó por subida de versión.
$n = $pdo->exec("UPDATE propuestas_history SET origen = 'save_version' WHERE origen IS NULL OR origen = ''");
$log[] = "· backfill de origen: " . (int)$n . " filas marcadas como save_version";

// NOTA deliberada: no se rellena aprobaciones.contenido_hash de las firmas
// antiguas. Se firmaron antes de que existiera el campo y el contenido de
// entonces no es reconstruible con garantías (dentro de una misma etiqueta de
// versión el documento pudo editarse en borrador después de la firma). Inventar
// una huella a posteriori sería peor que no tenerla.

$isCli = php_sapi_name() === 'cli';
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
echo "Migración registro de versiones aplicada:\n" . implode("\n", $log) . "\n";
