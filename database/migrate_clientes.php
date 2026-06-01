<?php
/**
 * Migración — Clientes firmantes para contratos eIDAS.
 *
 * Tabla:
 *   propuesta_clientes  — identidad de personas físicas que firman contratos
 *                         por parte del cliente (sin token/PIN propio · acceden
 *                         al doc con el PIN de la propuesta).
 *
 * Diseño:
 *   - Simétrica a propuesta_proveedores pero sin token ni PIN (no necesitan
 *     portal aparte como los proveedores).
 *   - Usada por admin_contratos.php cuando destinatario_tipo='cliente'.
 *   - Refleja personas, no empresas (la empresa va en propuestas.client_name).
 *
 * Uso:  php database/migrate_clientes.php
 * Idempotente.
 */

require __DIR__ . '/../config.php';
$pdo = getDBConnection();

$log = [];

$pdo->exec("
    CREATE TABLE IF NOT EXISTS propuesta_clientes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        propuesta_id INTEGER NOT NULL,
        nombre TEXT NOT NULL,
        email TEXT NOT NULL,
        empresa TEXT,
        cargo TEXT,
        dni TEXT,
        activo INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(propuesta_id) REFERENCES propuestas(id) ON DELETE CASCADE,
        UNIQUE(propuesta_id, email)
    )
");
$log[] = "+ propuesta_clientes";
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_propcli_prop ON propuesta_clientes(propuesta_id)");

echo "Migration OK\n";
foreach ($log as $line) echo "  $line\n";

// Verificación rápida
$count = (int)$pdo->query("SELECT COUNT(*) FROM propuesta_clientes")->fetchColumn();
echo "  propuesta_clientes rows: $count\n";
