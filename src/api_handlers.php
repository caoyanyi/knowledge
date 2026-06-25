<?php

require_once __DIR__ . '/app_helper.php';
require_once __DIR__ . '/knowledge_helper.php';

function handleChatApi(): void
{
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
}

function handleChatStreamApi(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

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
            return;
        }

        $startedAt = microtime(true);
        $fullAnswer = '';
        $pdo = createPdo($env, false);
        $knowledge = loadKnowledgeContextSafely($env, $message);
        $historyItems = loadChatHistorySafely($env, $pdo, $sessionId);
        $inputMessages = buildChatInputMessages($env, $message, $knowledge['context'], $historyItems);
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

        saveChatLogSafely($env, $pdo, $sessionId, $message, $fullAnswer, $startedAt);
    } catch (Throwable $e) {
        http_response_code(500);
        echo $e->getMessage();
    }
}

function handleSessionsApi(): void
{
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
}

function handleSessionMessagesApi(?string $sessionId = null): void
{
    try {
        $sessionId = sanitizeSessionId($sessionId ?? ($_GET['session_id'] ?? ''));

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
}

function handleCreateKnowledgeChunkApi(): void
{
    try {
        $env = loadEnv();
        $body = readJsonBody();

        $title = trim($body['title'] ?? '');
        $content = trim($body['content'] ?? '');
        $source = trim($body['source'] ?? envString($env, 'KNOWLEDGE_DEFAULT_SOURCE', '后台录入'));

        if ($title === '' || $content === '') {
            sendJsonError('标题和内容不能为空', 400);
        }

        $chunks = splitTextIntoChunks(
            $content,
            envInt($env, 'KNOWLEDGE_CHUNK_MAX_LENGTH', 800, 1),
            envInt($env, 'KNOWLEDGE_CHUNK_OVERLAP', 100, 0)
        );

        if (!$chunks) {
            sendJsonError('内容切分失败', 400);
        }

        $pdo = createPdo($env);
        $pdo->beginTransaction();

        $result = saveKnowledgeChunks($env, $pdo, $title, $source, $chunks);
        $pdo->commit();

        sendJson([
            'ok' => true,
            'ids' => $result['ids'],
            'chunk_count' => count($chunks),
            'message' => '知识库保存并同步成功',
        ]);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        sendJsonError($e->getMessage(), 500);
    }
}

function loadKnowledgeContextSafely(array $env, string $message): array
{
    try {
        return buildKnowledgeContext($env, $message);
    } catch (Throwable $e) {
        // 检索失败时不中断问答流程，由提示词明确说明资料不足。
        return [
            'context' => '',
            'sources' => [],
            'chunks' => [],
        ];
    }
}

function loadChatHistorySafely(array $env, ?PDO $pdo, string $sessionId): array
{
    if (!$pdo || $sessionId === '') {
        return [];
    }

    try {
        return fetchChatHistory($pdo, $sessionId, envInt($env, 'CHAT_HISTORY_LIMIT', 50, 0));
    } catch (Throwable $e) {
        return [];
    }
}

function buildChatInputMessages(array $env, string $message, string $knowledgeContext, array $historyItems): array
{
    $userContent = buildKnowledgePrompt($env, $message, $knowledgeContext);

    if ($knowledgeContext === '') {
        return [
            [
                'role' => 'user',
                'content' => $userContent,
            ],
        ];
    }

    return array_merge($historyItems, [
        [
            'role' => 'user',
            'content' => $userContent,
        ],
    ]);
}

function saveChatLogSafely(
    array $env,
    ?PDO $pdo,
    string $sessionId,
    string $message,
    string $answer,
    float $startedAt
): void {
    if (!$pdo || $answer === '') {
        return;
    }

    try {
        saveChatLog(
            $pdo,
            $sessionId,
            $message,
            $answer,
            envString($env, 'OPENAI_MODEL'),
            (int) round((microtime(true) - $startedAt) * 1000)
        );
    } catch (Throwable $e) {
        // 日志失败不影响已经返回给用户的答案。
    }
}

function saveKnowledgeChunks(array $env, PDO $pdo, string $title, string $source, array $chunks): array
{
    $insertStmt = $pdo->prepare("
        INSERT INTO knowledge_chunks (title, content, source)
        VALUES (:title, :content, :source)
    ");

    $points = [];
    $createdIds = [];

    foreach ($chunks as $index => $chunkContent) {
        $chunkTitle = count($chunks) > 1
            ? $title . ' - 片段' . ($index + 1)
            : $title;

        $insertStmt->execute([
            ':title' => $chunkTitle,
            ':content' => $chunkContent,
            ':source' => $source,
        ]);

        $chunkId = (int) $pdo->lastInsertId();
        $createdIds[] = $chunkId;

        $vector = createEmbedding($env, $chunkTitle . "\n" . $chunkContent);
        $points[] = buildKnowledgePoint($chunkId, $chunkTitle, $chunkContent, $source, $vector);
    }

    upsertQdrantPoints($env, $points);

    return [
        'ids' => $createdIds,
    ];
}
