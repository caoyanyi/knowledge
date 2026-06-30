<?php

require_once __DIR__ . '/api_handlers.php';

/**
 * 从请求地址中剥离 /api 前缀，保留版本化 API 路径用于路由匹配。
 */
function apiPath(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    if (str_starts_with($path, '/api')) {
        $path = substr($path, strlen('/api')) ?: '/';
    }

    return '/' . trim($path, '/');
}

/**
 * 返回统一的 API 404 响应。
 */
function sendApiNotFound(): void
{
    sendJson([
        'ok' => false,
        'error' => 'API endpoint not found',
    ], 404);
}

/**
 * 返回 405，并通过 Allow 头告诉前端当前接口支持的方法。
 */
function sendApiMethodNotAllowed(array $allowedMethods): void
{
    header('Allow: ' . implode(', ', $allowedMethods));
    sendJson([
        'ok' => false,
        'error' => 'Method not allowed',
    ], 405);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = apiPath();

if ($method === 'OPTIONS') {
    // 预检请求不进入业务处理，方便未来从其他前端域名调用。
    header('Allow: GET, POST, PUT, DELETE, OPTIONS');
    http_response_code(204);
    exit;
}

if ($path === '/v1/chat') {
    $method === 'POST' ? handleChatApi() : sendApiMethodNotAllowed(['POST']);
    exit;
}

if ($path === '/v1/chat/stream') {
    $method === 'POST' ? handleChatStreamApi() : sendApiMethodNotAllowed(['POST']);
    exit;
}

if ($path === '/v1/sessions') {
    $method === 'GET' ? handleSessionsApi() : sendApiMethodNotAllowed(['GET']);
    exit;
}

if (preg_match('#^/v1/sessions/([^/]+)/messages$#', $path, $matches)) {
    $method === 'GET' ? handleSessionMessagesApi($matches[1]) : sendApiMethodNotAllowed(['GET']);
    exit;
}

if ($path === '/v1/knowledge-chunks') {
    if ($method === 'GET') {
        handleListKnowledgeChunksApi();
    } elseif ($method === 'POST') {
        handleCreateKnowledgeChunkApi();
    } else {
        sendApiMethodNotAllowed(['GET', 'POST']);
    }
    exit;
}

if ($path === '/v1/knowledge-chunks/sync') {
    $method === 'POST' ? handleSyncKnowledgeChunksApi() : sendApiMethodNotAllowed(['POST']);
    exit;
}

if ($path === '/v1/knowledge-files') {
    $method === 'POST' ? handleUploadKnowledgeFileApi() : sendApiMethodNotAllowed(['POST']);
    exit;
}

if (preg_match('#^/v1/knowledge-chunks/(\d+)$#', $path, $matches)) {
    if ($method === 'GET') {
        handleGetKnowledgeChunkApi((int) $matches[1]);
    } elseif ($method === 'PUT') {
        handleUpdateKnowledgeChunkApi((int) $matches[1]);
    } elseif ($method === 'DELETE') {
        handleDeleteKnowledgeChunkApi((int) $matches[1]);
    } else {
        sendApiMethodNotAllowed(['GET', 'PUT', 'DELETE']);
    }
    exit;
}

sendApiNotFound();
