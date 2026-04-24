<?php
/**
 * Migración — hardening del sistema de contratos (auditoría previa deploy).
 *
 * Añade:
 *   - contratos_firmas.otp_hash (sha256 hex del OTP) — reemplaza otp_code como verdad
 *   - contratos_firmas.otp_attempts (contador intentos fallidos, bloquea a los 5)
 *   - contratos_firmas.otp_last_attempt_at (rate-limit request_otp)
 *   - contratos.signing_token_expires_at (fecha caducidad del token público)
 *
 * No toca otp_code (se mantiene por compat; se limpia al verificar para no dejar plaintext).
 * Idempotente: se puede re-ejecutar.
 */
require __DIR__ . '/../config.php';
$pdo = getDBConnection();

// Guard: si las tablas base no existen, abortar con mensaje claro
$baseTable = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='contratos_firmas'")->fetchColumn();
if (!$baseTable) {
    echo "⚠ Falta ejecutar primero database/migrate_contratos.php\n";
    exit(1);
}

$log = [];

function column_exists(PDO $pdo, string $table, string $col): bool {
    foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if ($c['name'] === $col) return true;
    }
    return false;
}

// --- contratos_firmas: otp_hash / otp_attempts / otp_last_attempt_at ---
if (!column_exists($pdo, 'contratos_firmas', 'otp_hash')) {
    $pdo->exec("ALTER TABLE contratos_firmas ADD COLUMN otp_hash TEXT");
    $log[] = "+ contratos_firmas.otp_hash";
}
if (!column_exists($pdo, 'contratos_firmas', 'otp_attempts')) {
    $pdo->exec("ALTER TABLE contratos_firmas ADD COLUMN otp_attempts INTEGER DEFAULT 0");
    $log[] = "+ contratos_firmas.otp_attempts";
}
if (!column_exists($pdo, 'contratos_firmas', 'otp_last_attempt_at')) {
    $pdo->exec("ALTER TABLE contratos_firmas ADD COLUMN otp_last_attempt_at DATETIME");
    $log[] = "+ contratos_firmas.otp_last_attempt_at";
}

// --- contratos: signing_token_expires_at ---
if (!column_exists($pdo, 'contratos', 'signing_token_expires_at')) {
    $pdo->exec("ALTER TABLE contratos ADD COLUMN signing_token_expires_at DATETIME");
    $log[] = "+ contratos.signing_token_expires_at";

    // Backfill: usar expira_at si existe, si no +30 días desde created_at
    $pdo->exec("
        UPDATE contratos
        SET signing_token_expires_at = COALESCE(expira_at, datetime(created_at, '+30 days'))
        WHERE signing_token_expires_at IS NULL AND signing_token IS NOT NULL
    ");
    $log[] = "  · backfill signing_token_expires_at (usa expira_at o +30d)";
}

// Migrar otp_code legado → otp_hash si hay OTPs en plaintext todavía vigentes
$legacy = $pdo->query("SELECT id, otp_code FROM contratos_firmas WHERE otp_code IS NOT NULL AND otp_code != '' AND (otp_hash IS NULL OR otp_hash = '')")->fetchAll(PDO::FETCH_ASSOC);
foreach ($legacy as $row) {
    $hash = hash('sha256', $row['otp_code']);
    $pdo->prepare("UPDATE contratos_firmas SET otp_hash = ?, otp_code = NULL WHERE id = ?")->execute([$hash, $row['id']]);
}
if ($legacy) $log[] = "  · migrados " . count($legacy) . " OTPs legacy plaintext → hash";

$isCli = php_sapi_name() === 'cli';
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
echo "Migración hardening contratos aplicada:\n" . (empty($log) ? "(nada que hacer, ya estaba al día)" : implode("\n", $log)) . "\n";
