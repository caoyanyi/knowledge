<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicRoot = __DIR__ . '/public';
$filePath = $path === '/'
    ? $publicRoot . '/index.html'
    : $publicRoot . $path;

if (is_file($filePath)) {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $contentTypes = [
        'css' => 'text/css; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
    ];

    header('Content-Type: ' . ($contentTypes[$extension] ?? 'application/octet-stream'));
    readfile($filePath);
    return true;
}

if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/src/api.php';
    return true;
}

return false;
