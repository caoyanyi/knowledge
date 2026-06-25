<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$filePath = __DIR__ . $path;

if ($path !== '/' && is_file($filePath)) {
    return false;
}

if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/api.php';
    return true;
}

return false;
