<?php

// PHP 内置服务器入口：手动处理 public 静态资源和 /api 请求。
set_time_limit(0);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicRoot = __DIR__ . '/public';
$filePath = $path === '/'
    ? $publicRoot . '/index.html'
    : $publicRoot . $path;

if (is_file($filePath)) {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    // 内置服务器不会自动为手动 readfile 的资源设置类型，这里补齐常用类型。
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
    // 所有后端请求统一进入版本化 API 路由，前端不再直接访问 PHP 脚本。
    require __DIR__ . '/src/api.php';
    return true;
}

return false;
