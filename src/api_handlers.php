<?php

require_once __DIR__ . '/app_helper.php';
require_once __DIR__ . '/knowledge_helper.php';

/**
 * 非流式问答接口，主要用于简单调试或不支持 ReadableStream 的场景。
 */
function handleChatApi(): void
{
    try {
        $env = loadEnv();
        $body = readJsonBody();
        $message = trim($body['message'] ?? '');

        if ($message === '') {
            sendJsonError('message 不能为空', 400);
        }

        $knowledge = loadKnowledgeContextSafely($env, $message);
        $inputMessages = buildChatInputMessages($env, $message, $knowledge['context'], []);
        $payload = buildResponsesPayload($env, $inputMessages);
        $data = callOpenAiResponse($env, $payload);

        sendJson([
            'ok' => true,
            'answer' => extractResponseText($data),
            'sources' => $knowledge['sources'],
            'warning' => $knowledge['warning'] ?? '',
        ]);
    } catch (Throwable $e) {
        sendJsonError($e->getMessage(), 500);
    }
}

/**
 * 流式问答接口：先拼接知识库上下文和历史对话，再边生成边输出。
 */
function handleChatStreamApi(): void
{
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    set_time_limit(0);

    // 关闭 PHP 输出缓冲，保证模型增量能尽快到达浏览器。
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
            sendStreamEvent('error', [
                'message' => 'message 不能为空',
            ]);
            return;
        }

        $startedAt = microtime(true);
        $fullAnswer = '';
        $pdo = createPdo($env, false);
        $knowledge = loadKnowledgeContextSafely($env, $message);
        if (($knowledge['warning'] ?? '') !== '') {
            sendStreamEvent('warning', [
                'message' => $knowledge['warning'],
            ]);
        }

        $historyItems = loadChatHistorySafely($env, $pdo, $sessionId);
        $inputMessages = buildChatInputMessages($env, $message, $knowledge['context'], $historyItems);
        $payload = buildChatCompletionsPayload($env, $inputMessages, true);

        // 文本增量用 NDJSON 事件输出，保证每个流片段都是可解析 JSON。
        $receivedDelta = streamOpenAiChatCompletion(
            $env,
            $payload,
            function (string $delta) use (&$fullAnswer) {
                $fullAnswer .= $delta;
                sendStreamEvent('delta', [
                    'text' => $delta,
                ]);
            },
            function (string $message) {
                sendStreamEvent('error', [
                    'message' => $message,
                ]);
            }
        );

        if (!$receivedDelta) {
            $fallbackPayload = buildResponsesPayload($env, $inputMessages, false);
            $fallbackAnswer = extractResponseText(callOpenAiResponse($env, $fallbackPayload));

            if ($fallbackAnswer !== '') {
                $fullAnswer = $fallbackAnswer;
                sendStreamEvent('delta', [
                    'text' => $fallbackAnswer,
                ]);
            }
        }

        if ($knowledge['sources']) {
            sendStreamEvent('sources', [
                'sources' => $knowledge['sources'],
            ]);
        }

        saveChatLogSafely($env, $pdo, $sessionId, $message, $fullAnswer, $startedAt, $knowledge['sources']);
        sendStreamEvent('done', [
            'session_id' => $sessionId,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        sendStreamEvent('error', [
            'message' => $e->getMessage(),
        ]);
    }
}

/**
 * 输出一行 JSON 流事件，前端和 curl 都可以逐行解析。
 */
function sendStreamEvent(string $type, array $payload = []): void
{
    echo json_encode(array_merge([
        'type' => $type,
    ], $payload), JSON_UNESCAPED_UNICODE) . "\n";

    if (ob_get_level() > 0) {
        ob_flush();
    }

    flush();
}

/**
 * 获取最近会话列表。
 */
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

/**
 * 获取单个会话下的历史消息。
 */
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

/**
 * 新增知识库内容，并同步写入 MySQL 与 Qdrant。
 */
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

/**
 * 安全加载知识库上下文；检索失败不阻断聊天主流程。
 */
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
            'warning' => '知识库检索暂时不可用，已切换为无参考资料回答：' . $e->getMessage(),
        ];
    }
}

/**
 * 安全加载历史对话；数据库不可用时模型仍可回答当前问题。
 */
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

/**
 * 将历史消息和知识库增强提示词组合为 Responses API 输入。
 */
function buildChatInputMessages(array $env, string $message, string $knowledgeContext, array $historyItems): array
{
    $userContent = buildKnowledgePrompt($env, $message, $knowledgeContext);

    if ($knowledgeContext === '') {
        // 未检索到资料时不带历史，降低模型受旧上下文影响而编造答案的概率。
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

/**
 * 保存问答日志；失败时静默处理，避免影响已经返回给用户的流式答案。
 */
function saveChatLogSafely(
    array $env,
    ?PDO $pdo,
    string $sessionId,
    string $message,
    string $answer,
    float $startedAt,
    array $sources = []
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
            (int) round((microtime(true) - $startedAt) * 1000),
            $sources
        );
    } catch (Throwable $e) {
        // 日志失败不影响已经返回给用户的答案。
    }
}

/**
 * 保存切分后的知识片段，并为每个片段生成向量后写入 Qdrant。
 */
function saveKnowledgeChunks(array $env, PDO $pdo, string $title, string $source, array $chunks): array
{
    $insertStmt = $pdo->prepare("
        INSERT INTO knowledge_chunks (title, content, source)
        VALUES (:title, :content, :source)
    ");

    $points = [];
    $createdIds = [];

    // MySQL 自增 ID 作为 Qdrant point ID，方便删除和重同步时保持一致。
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

/**
 * 列出最近录入的知识片段，供后台页面管理。
 */
function handleListKnowledgeChunksApi(): void
{
    try {
        $env = loadEnv();
        $pdo = createPdo($env);

        $stmt = $pdo->query("
            SELECT
                id,
                title,
                source,
                CHAR_LENGTH(content) AS content_length,
                LEFT(content, 120) AS preview,
                created_at
            FROM knowledge_chunks
            ORDER BY id DESC
            LIMIT 100
        ");

        sendJson([
            'ok' => true,
            'items' => $stmt->fetchAll(),
        ]);
    } catch (Throwable $e) {
        sendJsonError($e->getMessage(), 500);
    }
}

/**
 * 删除单条知识片段，并同步删除向量库中的 point。
 */
function handleDeleteKnowledgeChunkApi(int $id): void
{
    try {
        if ($id <= 0) {
            sendJsonError('id 不合法', 400);
        }

        $env = loadEnv();
        $pdo = createPdo($env);

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("DELETE FROM knowledge_chunks WHERE id = :id");
        $stmt->execute([':id' => $id]);

        // 删除向量失败时会抛出异常并回滚 MySQL 删除，保持两边状态一致。
        deleteQdrantPoints($env, [$id]);

        $pdo->commit();

        sendJson([
            'ok' => true,
            'id' => $id,
            'message' => '知识片段已删除',
        ]);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        sendJsonError($e->getMessage(), 500);
    }
}

/**
 * 将 MySQL 中现有知识片段全量重新写入 Qdrant。
 */
function handleSyncKnowledgeChunksApi(): void
{
    try {
        $env = loadEnv();
        $pdo = createPdo($env);

        $rows = $pdo->query("
            SELECT id, title, content, source
            FROM knowledge_chunks
            ORDER BY id ASC
        ")->fetchAll();

        $points = [];

        foreach ($rows as $row) {
            $vector = createEmbedding($env, $row['title'] . "\n" . $row['content']);

            $points[] = buildKnowledgePoint(
                (int) $row['id'],
                $row['title'],
                $row['content'],
                $row['source'],
                $vector
            );
        }

        upsertQdrantPoints($env, $points);

        sendJson([
            'ok' => true,
            'synced_count' => count($points),
            'message' => '知识库已重新同步到 Qdrant',
        ]);
    } catch (Throwable $e) {
        sendJsonError($e->getMessage(), 500);
    }
}

/**
 * 上传知识文件到知识库。
 */
function handleUploadKnowledgeFileApi(): void
{
    try {
        $env = loadEnv();

        if (!isset($_FILES['file'])) {
            sendJsonError('请上传文件', 400);
            return;
        }

        $file = $_FILES['file'];

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            sendJsonError('文件上传失败', 400);
            return;
        }

        $originalName = $file['name'] ?? '';
        $tmpPath = $file['tmp_name'] ?? '';

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($ext, ['txt', 'md'], true)) {
            sendJsonError('当前仅支持 txt 和 md 文件', 400);
            return;
        }

        $maxBytes = envInt($env, 'KNOWLEDGE_UPLOAD_MAX_BYTES', 1024 * 1024 * 2, 1);

        if (($file['size'] ?? 0) > $maxBytes) {
            sendJsonError('文件过大，请控制在 2MB 以内', 400);
            return;
        }

        $content = file_get_contents($tmpPath);

        if ($content === false || trim($content) === '') {
            sendJsonError('文件内容为空', 400);
            return;
        }

        $title = trim($_POST['title'] ?? '');

        if ($title === '') {
            $title = pathinfo($originalName, PATHINFO_FILENAME);
        }

        $source = '文件上传：' . $originalName;

        $chunks = splitTextIntoChunks(
            $content,
            envInt($env, 'KNOWLEDGE_CHUNK_MAX_LENGTH', 800, 1),
            envInt($env, 'KNOWLEDGE_CHUNK_OVERLAP', 100, 0)
        );

        if (!$chunks) {
            sendJsonError('文件切分失败', 400);
            return;
        }

        $pdo = createPdo($env);
        $pdo->beginTransaction();

        $result = saveKnowledgeChunks($env, $pdo, $title, $source, $chunks);

        $pdo->commit();

        sendJson([
            'ok' => true,
            'filename' => $originalName,
            'ids' => $result['ids'],
            'chunk_count' => count($chunks),
            'message' => '文件上传并同步成功',
        ]);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        sendJsonError($e->getMessage(), 500);
    }
}


function handleGetKnowledgeChunkApi(int $id): void
{
    try {
        if ($id <= 0) {
            sendJsonError('id 不合法', 400);
            return;
        }

        $env = loadEnv();
        $pdo = createPdo($env);

        $stmt = $pdo->prepare("
            SELECT id, title, content, source, created_at
            FROM knowledge_chunks
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();

        if (!$row) {
            sendJsonError('知识片段不存在', 404);
            return;
        }

        sendJson([
            'ok' => true,
            'item' => $row,
        ]);
    } catch (Throwable $e) {
        sendJsonError($e->getMessage(), 500);
    }
}

function handleUpdateKnowledgeChunkApi(int $id): void
{
    try {
        if ($id <= 0) {
            sendJsonError('id 不合法', 400);
            return;
        }

        $env = loadEnv();
        $body = readJsonBody();

        $title = trim($body['title'] ?? '');
        $content = trim($body['content'] ?? '');
        $source = trim($body['source'] ?? '');

        if ($title === '' || $content === '') {
            sendJsonError('标题和内容不能为空', 400);
            return;
        }

        $pdo = createPdo($env);
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT id
            FROM knowledge_chunks
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([':id' => $id]);

        if (!$stmt->fetch()) {
            $pdo->rollBack();
            sendJsonError('知识片段不存在', 404);
            return;
        }

        $updateStmt = $pdo->prepare("
            UPDATE knowledge_chunks
            SET title = :title,
                content = :content,
                source = :source
            WHERE id = :id
        ");

        $updateStmt->execute([
            ':id' => $id,
            ':title' => $title,
            ':content' => $content,
            ':source' => $source,
        ]);

        $vector = createEmbedding($env, $title . "\n" . $content);

        upsertQdrantPoints($env, [
            buildKnowledgePoint($id, $title, $content, $source, $vector),
        ]);

        $pdo->commit();

        sendJson([
            'ok' => true,
            'id' => $id,
            'message' => '知识片段已更新并同步到 Qdrant',
        ]);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        sendJsonError($e->getMessage(), 500);
    }
}
