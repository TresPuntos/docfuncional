<?php
/**
 * Router script for the PHP built-in web server.
 * This simulates the .htaccess rewrite rule: /p/(slug) -> view.php?id=(slug)
 */

$uri = $_SERVER['REQUEST_URI'];

// Si el request es para /p/(slug)
if (preg_match('/^\/p\/([a-zA-Z0-9_-]+)\/?/', $uri, $matches)) {
    $_GET['id'] = $matches[1];
    include __DIR__ . '/view.php';
    return true;
}

// Si el archivo existe físicamente, dejar que el servidor lo sirva
if (file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false;
}

// Comportamiento por defecto
return false;