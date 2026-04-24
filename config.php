<?php
/**
 * Configuración global del sistema "Tres Puntos" Proposal CRM
 */

// Evitar acceso directo
if (basename($_SERVER['PHP_SELF']) === 'config.php') {
    die('Acceso denegado');
}

// === CONFIGURACIÓN DE SEGURIDAD ===
// Contraseña de acceso al panel de administración
define('ADMIN_PASSWORD', 'TresPuntos2026!'); // Cambiar por una contraseña segura

// === API TOKEN PARA AGENTES IA ===
define('API_TOKEN', 'tp_f06125ce7729d6b8dde738b7fb1a43cd27492aee332a325cb504bb27a73315e7');

// === CONFIGURACIÓN DE BASE DE DATOS ===
define('DB_PATH', __DIR__ . '/database/database.sqlite'); // Archivo oculto en subdirectorio

// === CONFIGURACIÓN TELEGRAM API ===
define('TELEGRAM_BOT_TOKEN', '8201699988:AAHZ6UeItc1I6EkULkrV6mozxkLO80t7j58');
define('TELEGRAM_CHAT_ID', '7313439878');

// === CONFIGURACIÓN ANTHROPIC (Jordan-doc) ===
// La clave real vive en config.local.php (fuera del repo). Si no existe,
// Jordan-doc quedará desactivado y el endpoint devolverá 503.
// IMPORTANTE: el require_once va ANTES de los defaults, porque las
// constantes no se pueden redefinir en PHP.
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}
if (!defined('ANTHROPIC_API_KEY')) define('ANTHROPIC_API_KEY', '');
if (!defined('ANTHROPIC_MODEL')) define('ANTHROPIC_MODEL', 'claude-haiku-4-5');
if (!defined('JORDAN_DOC_ENABLED')) define('JORDAN_DOC_ENABLED', false);

// === EMAILS INTERNOS (equipo Tres Puntos) ===
// Coma-separada: cuando alguien entra a /p/ o /s/ con un email de esta lista,
// los eventos se marcan is_internal=1 y NO cuentan en analytics del cliente
// (ni EN VIVO, ni sesiones, ni firmas detectadas, etc.). Definir en config.local.php:
//   define('INTERNAL_EMAILS', 'jordi@trespuntoscomunicacion.es,jordiexp@gmail.com');
if (!defined('INTERNAL_EMAILS')) define('INTERNAL_EMAILS', '');

/**
 * ¿Es este email parte del equipo interno? (case-insensitive, trim)
 */
function isInternalEmail(string $email): bool
{
    if (!defined('INTERNAL_EMAILS') || !INTERNAL_EMAILS) return false;
    $list = array_filter(array_map('strtolower', array_map('trim', explode(',', INTERNAL_EMAILS))));
    return in_array(strtolower(trim($email)), $list, true);
}

// === HELPER CONEXIÓN PDO ===
function getDBConnection()
{
    try {
        // Asegurarnos de que el directorio database/ exista
        $dbDir = dirname(DB_PATH);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
            // Proteger el directorio con .htaccess si lo acabamos de crear
            file_put_contents($dbDir . '/.htaccess', "Deny from all");
        }

        $pdo = new PDO('sqlite:' . DB_PATH);
        // Configurar PDO para que lance excepciones en caso de error
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Habilitar prepared statements nativos para SQLite
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        // Habilitar claves foráneas
        $pdo->exec('PRAGMA foreign_keys = ON;');

        // === CONCURRENCIA SQLITE ===
        // WAL mode permite lecturas concurrentes durante escrituras (mucho mejor
        // para apps con tracking + comments + jordan + analytics corriendo en paralelo).
        // busy_timeout: si la BD está bloqueada por otra escritura, SQLite espera
        // hasta 5s antes de lanzar "database is locked" (fix del bug de Jordan 500
        // detectado 2026-04-24).
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA busy_timeout = 5000;');
        $pdo->exec('PRAGMA synchronous = NORMAL;');
        return $pdo;
    }
    catch (PDOException $e) {
        die("Error de conexión a la base de datos: " . $e->getMessage());
    }
}
?>