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
        return $pdo;
    }
    catch (PDOException $e) {
        die("Error de conexión a la base de datos: " . $e->getMessage());
    }
}
?>