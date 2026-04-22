<?php
/**
 * Migración — Portal de proveedores.
 *
 * Tablas:
 *   propuesta_proveedores   — identidad del proveedor invitado (token + PIN)
 *   proveedor_presupuestos  — PDF + importe + plazo + notas (N versiones por proveedor)
 *   proveedor_mensajes      — comentarios del proveedor sobre la propuesta (separado del cliente)
 *
 * Uso:  php database/migrate_providers.php
 * Idempotente.
 */

require __DIR__ . '/../config.php';
$pdo = getDBConnection();

$log = [];

$pdo->exec("
    CREATE TABLE IF NOT EXISTS propuesta_proveedores (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        propuesta_id INTEGER NOT NULL,
        nombre TEXT NOT NULL,
        empresa TEXT,
        email TEXT NOT NULL,
        token TEXT NOT NULL UNIQUE,
        pin TEXT NOT NULL,
        ver_comentarios INTEGER DEFAULT 1,
        activo INTEGER DEFAULT 1,
        invited_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_accessed_at DATETIME,
        accesos INTEGER DEFAULT 0,
        FOREIGN KEY(propuesta_id) REFERENCES propuestas(id) ON DELETE CASCADE
    )
");
$log[] = "+ propuesta_proveedores";
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_prov_token ON propuesta_proveedores(token)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_prov_prop ON propuesta_proveedores(propuesta_id)");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS proveedor_presupuestos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        proveedor_id INTEGER NOT NULL,
        archivo_path TEXT NOT NULL,
        archivo_nombre TEXT NOT NULL,
        archivo_size INTEGER,
        archivo_mime TEXT,
        importe_total REAL,
        plazo_dias INTEGER,
        moneda TEXT DEFAULT 'EUR',
        notas TEXT,
        version_num INTEGER DEFAULT 1,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(proveedor_id) REFERENCES propuesta_proveedores(id) ON DELETE CASCADE
    )
");
$log[] = "+ proveedor_presupuestos";
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_pres_prov ON proveedor_presupuestos(proveedor_id)");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS proveedor_mensajes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        proveedor_id INTEGER NOT NULL,
        section_anchor TEXT,
        section_title TEXT,
        autor_tipo TEXT NOT NULL,
        autor_nombre TEXT,
        texto TEXT NOT NULL,
        parent_id INTEGER,
        resuelto INTEGER DEFAULT 0,
        is_draft INTEGER DEFAULT 0,
        notificado_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(proveedor_id) REFERENCES propuesta_proveedores(id) ON DELETE CASCADE,
        FOREIGN KEY(parent_id) REFERENCES proveedor_mensajes(id) ON DELETE CASCADE
    )
");
$log[] = "+ proveedor_mensajes";
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_msg_prov ON proveedor_mensajes(proveedor_id)");

// Carpeta uploads para presupuestos de proveedores
$uploadsDir = __DIR__ . '/../uploads/proveedores';
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
    $log[] = "+ uploads/proveedores/";
}
// htaccess para bloquear acceso directo a los PDFs subidos
$htaccess = $uploadsDir . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Deny from all\n");
    $log[] = "+ uploads/proveedores/.htaccess (Deny all)";
}

$isCli = php_sapi_name() === 'cli';
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
echo "Migración providers aplicada:\n" . implode("\n", $log) . "\n";
