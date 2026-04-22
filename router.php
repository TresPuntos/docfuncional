<?php
// router.php for local development
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// If it's a /p/slug request, route to view.php
if (preg_match('/^\/p\/([a-zA-Z0-9_-]+)/', $uri, $matches)) {
    $slug = $matches[1];
    $_GET['id'] = $slug;
    include __DIR__ . '/view.php';
    exit;
}

// Provider portal: /s/{token}
if (preg_match('/^\/s\/([a-f0-9]{24,48})/', $uri, $matches)) {
    $_GET['token'] = $matches[1];
    include __DIR__ . '/provider.php';
    exit;
}

// Default behavior for other files
if (file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false; // let the built-in server serve the static file
}

// Route everything else
return false;