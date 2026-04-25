<?php
/**
 * Migración — Sistema de contratos con firma electrónica eIDAS simple.
 *
 * Tablas:
 *   contratos_plantillas  — HTML reutilizable con placeholders {{variable}}
 *   contratos             — instancia asignada a propuesta + contraparte
 *   contratos_firmas      — firma individual (multi-firma soportado)
 *   contratos_eventos     — audit trail cronológico (creado/enviado/visto/firmado)
 *
 * Uso:  php database/migrate_contratos.php
 * Idempotente — se puede ejecutar varias veces sin romper nada.
 */

require __DIR__ . '/../config.php';
$pdo = getDBConnection();

$log = [];

// ====================================================================
// 1) Plantillas reusables
// ====================================================================
$pdo->exec("
    CREATE TABLE IF NOT EXISTS contratos_plantillas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT NOT NULL UNIQUE,
        nombre TEXT NOT NULL,
        tipo TEXT NOT NULL,
        destinatario TEXT NOT NULL,
        html_content TEXT NOT NULL,
        variables_json TEXT,
        firmantes_json TEXT,
        require_otp INTEGER DEFAULT 0,
        require_tsa INTEGER DEFAULT 1,
        retencion_anios INTEGER DEFAULT 6,
        version INTEGER DEFAULT 1,
        activo INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");
$log[] = "+ contratos_plantillas";
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_plant_slug ON contratos_plantillas(slug)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_plant_tipo ON contratos_plantillas(tipo, activo)");

// ====================================================================
// 2) Instancia de contrato
// ====================================================================
$pdo->exec("
    CREATE TABLE IF NOT EXISTS contratos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        plantilla_id INTEGER,
        propuesta_id INTEGER,
        destinatario_tipo TEXT NOT NULL,
        destinatario_id INTEGER,
        titulo TEXT NOT NULL,
        datos_json TEXT,
        pdf_sin_firmar_path TEXT,
        pdf_firmado_path TEXT,
        estado TEXT DEFAULT 'borrador',
        hash_documento TEXT,
        hash_final TEXT,
        expira_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        enviado_at DATETIME,
        firmado_at DATETIME,
        FOREIGN KEY(plantilla_id) REFERENCES contratos_plantillas(id) ON DELETE SET NULL,
        FOREIGN KEY(propuesta_id) REFERENCES propuestas(id) ON DELETE SET NULL
    )
");
$log[] = "+ contratos";
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_ctr_prop ON contratos(propuesta_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_ctr_dest ON contratos(destinatario_tipo, destinatario_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_ctr_estado ON contratos(estado)");

// ====================================================================
// 3) Firmas individuales (multi-firma)
// ====================================================================
$pdo->exec("
    CREATE TABLE IF NOT EXISTS contratos_firmas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        contrato_id INTEGER NOT NULL,
        rol TEXT NOT NULL,
        orden INTEGER DEFAULT 1,
        firmante_nombre TEXT,
        firmante_email TEXT,
        firmante_documento TEXT,
        firmante_empresa TEXT,
        firmante_cargo TEXT,
        firmante_direccion TEXT,
        firma_trazo_base64 TEXT,
        firma_hash TEXT UNIQUE,
        otp_code TEXT,
        otp_expires_at DATETIME,
        otp_verified_at DATETIME,
        ip TEXT,
        geoip_country TEXT,
        user_agent TEXT,
        consent_texto TEXT,
        consent_aceptado INTEGER DEFAULT 0,
        signing_duration_ms INTEGER,
        scroll_depth_pct INTEGER,
        signing_method TEXT,
        tsa_timestamp TEXT,
        server_timestamp_utc DATETIME,
        client_timestamp TEXT,
        firmado_at DATETIME,
        FOREIGN KEY(contrato_id) REFERENCES contratos(id) ON DELETE CASCADE
    )
");
$log[] = "+ contratos_firmas";
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_fir_ctr ON contratos_firmas(contrato_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_fir_email ON contratos_firmas(firmante_email)");

// ====================================================================
// 4) Audit trail cronológico (eventos)
// ====================================================================
$pdo->exec("
    CREATE TABLE IF NOT EXISTS contratos_eventos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        contrato_id INTEGER NOT NULL,
        evento TEXT NOT NULL,
        actor TEXT,
        ip TEXT,
        user_agent TEXT,
        meta_json TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(contrato_id) REFERENCES contratos(id) ON DELETE CASCADE
    )
");
$log[] = "+ contratos_eventos";
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_evt_ctr ON contratos_eventos(contrato_id, created_at)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_evt_tipo ON contratos_eventos(evento)");

// ====================================================================
// 5) Estructura de carpetas + htaccess
// ====================================================================
$baseDir = __DIR__ . '/../uploads/contratos';
if (!is_dir($baseDir)) {
    mkdir($baseDir, 0755, true);
    $log[] = "+ uploads/contratos/";
}
$htaccess = $baseDir . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Deny from all\n");
    $log[] = "+ uploads/contratos/.htaccess (Deny all)";
}

// Carpeta para plantillas (PDFs base subidos por admin)
$plantDir = __DIR__ . '/../uploads/contratos_plantillas';
if (!is_dir($plantDir)) {
    mkdir($plantDir, 0755, true);
    $log[] = "+ uploads/contratos_plantillas/";
}
$htaccess2 = $plantDir . '/.htaccess';
if (!file_exists($htaccess2)) {
    file_put_contents($htaccess2, "Deny from all\n");
    $log[] = "+ uploads/contratos_plantillas/.htaccess (Deny all)";
}

$isCli = php_sapi_name() === 'cli';
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
echo "Migración contratos aplicada:\n" . implode("\n", $log) . "\n";
