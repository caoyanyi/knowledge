<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/app_helper.php';

try {
    $env = loadEnv();
    $body = readJsonBody();
    $message = trim($body['message'] ?? '');

    if ($message === '') {
        sendJsonError('message 不能为空', 400);
    }

    $payload = buildResponsesPayload($env, $message);
    $data = callOpenAiResponse($env, $payload);

    sendJson([
        'ok' => true,
        'answer' => extractResponseText($data),
    ]);
} catch (Throwable $e) {
    sendJsonError($e->getMessage(), 500);
}
