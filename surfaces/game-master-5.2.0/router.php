<?php
/**
 * router.php — PHP built-in dev server router
 * Run:  php -S localhost:8080 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// API → api.php
if (str_starts_with($uri, '/api')) {
    require __DIR__ . '/api.php';
    return true;
}

// Root → index.php
if ($uri === '/' || $uri === '') {
    require __DIR__ . '/index.php';
    return true;
}

// Static files under /public
$path = __DIR__ . $uri;
if (is_file($path)) {
    $map = [
        'css'  => 'text/css',
        'js'   => 'text/javascript',
        'mjs'  => 'text/javascript',   // ES modules: the client and siso/js (core 13.1.0)
        'json' => 'application/json',
        'html' => 'text/html',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
    ];
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    if (isset($map[$ext])) header('Content-Type: ' . $map[$ext]);
    return false;
}

// Any other PHP file
if (is_file(__DIR__ . $uri . '.php')) {
    require __DIR__ . $uri . '.php';
    return true;
}

http_response_code(404);
echo '404 Not Found';
return true;
