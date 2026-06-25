<?php

require_once __DIR__ . '/api_handlers.php';

function apiPath(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    if (str_starts_with($path, '/api')) {
        $path = substr($path, strlen('/api')) ?: '/';
    }

    return '/' . trim($path, '/');
}

function sendApiNotFound(): void
{
    sendJson([
        'ok' => false,
        'error' => 'API endpoint not found',
    ], 404);
}

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
    header('Allow: GET, POST, OPTIONS');
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
    $method === 'POST' ? handleCreateKnowledgeChunkApi() : sendApiMethodNotAllowed(['POST']);
    exit;
}

sendApiNotFound();
