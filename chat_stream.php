<?php

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

require_once __DIR__ . '/app_helper.php';
require_once __DIR__ . '/knowledge_helper.php';

set_time_limit(0);

while (ob_get_level() > 0) {
    ob_end_flush();
}

try {
    $env = loadEnv();
    $body = readJsonBody();

    $message = trim($body['message'] ?? '');
    $sessionId = sanitizeSessionId(trim($body['session_id'] ?? ''));

    if ($sessionId === '') {
        $sessionId = createSessionId();
    }

    if ($message === '') {
        http_response_code(400);
        echo 'message 不能为空';
        exit;
    }

    $startedAt = microtime(true);
    $fullAnswer = '';
    $pdo = createPdo($env, false);

    try {
        $knowledge = buildKnowledgeContext($env, $message);
    } catch (Throwable $e) {
        // 检索失败时不中断问答流程，由提示词明确说明资料不足。
        $knowledge = [
            'context' => '',
            'sources' => [],
            'chunks' => [],
        ];
    }

    $historyItems = [];

    if ($pdo && $sessionId !== '') {
        try {
            $historyItems = fetchChatHistory(
                $pdo,
                $sessionId,
                envInt($env, 'CHAT_HISTORY_LIMIT', 50, 0)
            );
        } catch (Throwable $e) {
            $historyItems = [];
        }
    }

    $userContent = buildKnowledgePrompt($env, $message, $knowledge['context']);
    $inputMessages = $knowledge['context'] !== ''
        ? array_merge($historyItems, [
            [
                'role' => 'user',
                'content' => $userContent,
            ],
        ])
        : [
            [
                'role' => 'user',
                'content' => $userContent,
            ],
        ];

    $payload = buildResponsesPayload($env, $inputMessages, true);

    streamOpenAiResponse(
        $env,
        $payload,
        function (string $delta) use (&$fullAnswer) {
            $fullAnswer .= $delta;
            echo $delta;
            flush();
        },
        function (string $message) {
            echo "\n[模型错误] {$message}";
            flush();
        }
    );

    if ($knowledge['sources']) {
        echo "\n" . STREAM_SOURCES_MARKER . json_encode([
            'sources' => $knowledge['sources'],
        ], JSON_UNESCAPED_UNICODE);
        flush();
    }

    if ($pdo && $fullAnswer !== '') {
        try {
            saveChatLog(
                $pdo,
                $sessionId,
                $message,
                $fullAnswer,
                envString($env, 'OPENAI_MODEL'),
                (int) round((microtime(true) - $startedAt) * 1000)
            );
        } catch (Throwable $e) {
            // 日志失败不影响已经返回给用户的答案。
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo $e->getMessage();
}
