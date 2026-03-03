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

// Default behavior for other files
if (file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false; // let the built-in server serve the static file
}

// Route everything else
return false;