<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/app_helper.php';

try {
    $sessionId = sanitizeSessionId($_GET['session_id'] ?? '');

    if ($sessionId === '') {
        sendJsonError('session_id 不能为空', 400);
    }

    $env = loadEnv();
    $pdo = createPdo($env);

    sendJson([
        'ok' => true,
        'messages' => fetchSessionMessages($pdo, $sessionId),
    ]);
} catch (Throwable $e) {
    sendJsonError($e->getMessage(), 500);
}
