<?php

require __DIR__ . '/embedding_helper.php';

$env = parse_ini_file(__DIR__ . '/.env');

function qdrantSearch(array $env, string $query, int $limit = 3): array
{
    $qdrantUrl = rtrim($env['QDRANT_URL'] ?? 'http://127.0.0.1:6333', '/');
    $collection = $env['QDRANT_COLLECTION'] ?? 'knowledge_chunks';

    $vector = createEmbedding($env, $query);

    $payload = [
        'vector' => $vector,
        'limit' => $limit,
        'with_payload' => true,
        'with_vector' => false,
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

    return $data['result'] ?? [];
}

$questions = [
    '企业AI知识库客服适合哪些行业？',
    '它的核心价值是什么？',
    '我们公司的退款政策是什么？',
];

foreach ($questions as $question) {
    echo "\n============================\n";
    echo "问题：{$question}\n";

    $results = qdrantSearch($env, $question, 3);

    foreach ($results as $item) {
        $payload = $item['payload'] ?? [];
        $score = $item['score'] ?? 0;

        echo "score: {$score}\n";
        echo "title: " . ($payload['title'] ?? '') . "\n";
        echo "content: " . mb_substr($payload['content'] ?? '', 0, 80) . "\n";
        echo "source: " . ($payload['source'] ?? '') . "\n";
        echo "----------------------------\n";
    }
}
