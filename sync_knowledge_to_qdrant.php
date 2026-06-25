<?php

require_once __DIR__ . '/app_helper.php';
require_once __DIR__ . '/knowledge_helper.php';

try {
    $env = loadEnv();
    $pdo = createPdo($env);

    $rows = $pdo->query("
        SELECT id, title, content, source
        FROM knowledge_chunks
        ORDER BY id ASC
    ")->fetchAll();

    $points = [];
    $batchSize = envInt($env, 'QDRANT_UPSERT_BATCH_SIZE', 64, 1);

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
            $response = upsertQdrantPoints($env, $points);
            echo "Qdrant HTTP: {$response['status']}\n";
            $points = [];
        }
    }

    if ($points) {
        $response = upsertQdrantPoints($env, $points);
        echo "Qdrant HTTP: {$response['status']}\n";
    }

    echo "同步完成，共 " . count($rows) . " 条知识片段。\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
