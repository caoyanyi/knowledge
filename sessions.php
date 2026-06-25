<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/app_helper.php';

try {
    $env = loadEnv();
    $pdo = createPdo($env);

    sendJson([
        'ok' => true,
        'sessions' => fetchRecentSessions($pdo, envInt($env, 'SESSION_LIST_LIMIT', 20, 1)),
    ]);
} catch (Throwable $e) {
    sendJsonError($e->getMessage(), 500);
}
