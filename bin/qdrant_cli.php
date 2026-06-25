<?php

require_once dirname(__DIR__) . '/src/app_helper.php';
require_once dirname(__DIR__) . '/src/knowledge_helper.php';

function printUsage(): void
{
    echo "用法：\n";
    echo "  php bin/qdrant_cli.php init   创建或更新 Qdrant collection\n";
    echo "  php bin/qdrant_cli.php sync   将 MySQL 知识片段同步到 Qdrant\n";
}

function runQdrantInit(array $env): void
{
    $response = createQdrantCollection($env);

    echo "Qdrant HTTP: {$response['status']}\n";
    echo $response['body'] . "\n";
}

function runQdrantSync(array $env): void
{
    $pdo = createPdo($env);
    $rows = $pdo->query("
        SELECT id, title, content, source
        FROM knowledge_chunks
        ORDER BY id ASC
    ")->fetchAll();

    $points = [];
    $batchSize = envInt($env, 'QDRANT_UPSERT_BATCH_SIZE', 64, 1);
    $syncedCount = 0;

    foreach ($rows as $row) {
        echo "生成向量：#{$row['id']} {$row['title']}\n";

        $vector = createEmbedding($env, $row['title'] . "\n" . $row['content']);
        $points[] = buildKnowledgePoint(
            (int) $row['id'],
            $row['title'],
            $row['content'],
            $row['source'],
            $vector
        );

        if (count($points) >= $batchSize) {
            $syncedCount += flushQdrantBatch($env, $points);
            $points = [];
        }
    }

    if ($points) {
        $syncedCount += flushQdrantBatch($env, $points);
    }

    echo "同步完成，共 {$syncedCount} 条知识片段。\n";
}

function flushQdrantBatch(array $env, array $points): int
{
    $response = upsertQdrantPoints($env, $points);
    echo "Qdrant HTTP: {$response['status']}，批量写入 " . count($points) . " 条\n";

    return count($points);
}

$command = $argv[1] ?? '';

try {
    $env = loadEnv();

    if ($command === 'init') {
        runQdrantInit($env);
        exit(0);
    }

    if ($command === 'sync') {
        runQdrantSync($env);
        exit(0);
    }

    printUsage();
    exit($command === '' ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
