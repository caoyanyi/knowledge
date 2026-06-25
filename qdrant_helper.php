<?php

require_once __DIR__ . '/embedding_helper.php';

function searchKnowledgeByVector(array $env, string $query, int $limit = 3): array
{
    $qdrantUrl = rtrim($env['QDRANT_URL'] ?? 'http://127.0.0.1:6333', '/');
    $collection = $env['QDRANT_COLLECTION'] ?? 'knowledge_chunks';
    $scoreThreshold = (float) ($env['QDRANT_SCORE_THRESHOLD'] ?? 0.45);

    $vector = createEmbedding($env, $query);

    $payload = [
        'vector' => $vector,
        'limit' => $limit,
        'with_payload' => true,
        'with_vector' => false,
        'score_threshold' => $scoreThreshold,
    ];

    $ch = curl_init($qdrantUrl . '/collections/' . $collection . '/points/search');

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        throw new RuntimeException('Qdrant 请求失败：' . curl_error($ch));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        throw new RuntimeException('Qdrant 接口错误：' . $response);
    }

    $data = json_decode($response, true);
    $results = $data['result'] ?? [];

    return array_map(function ($item) {
        $payload = $item['payload'] ?? [];

        return [
            'id' => $item['id'] ?? null,
            'score' => $item['score'] ?? 0,
            'title' => $payload['title'] ?? '',
            'content' => $payload['content'] ?? '',
            'source' => $payload['source'] ?? '',
        ];
    }, $results);
}
