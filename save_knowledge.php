<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/app_helper.php';
require_once __DIR__ . '/knowledge_helper.php';

set_time_limit(0);

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
    $pdo->commit();

    sendJson([
        'ok' => true,
        'ids' => $createdIds,
        'chunk_count' => count($chunks),
        'message' => '知识库保存并同步成功',
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    sendJsonError($e->getMessage(), 500);
}
