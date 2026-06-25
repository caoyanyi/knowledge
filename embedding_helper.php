<?php

function createEmbedding(array $env, string $text): array
{
    $apiKey = $env['EMBEDDING_API_KEY'] ?? '';
    $baseUrl = rtrim($env['EMBEDDING_BASE_URL'] ?? 'https://api.openai.com', '/');
    $model = $env['EMBEDDING_MODEL'] ?? 'text-embedding-3-small';

    $endpoint = str_ends_with($baseUrl, '/v1')
        ? $baseUrl . '/embeddings'
        : $baseUrl . '/v1/embeddings';

    $payload = [
        'model' => $model,
        'input' => $text,
    ];

    $ch = curl_init($endpoint);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        throw new RuntimeException('Embedding 请求失败：' . curl_error($ch));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpCode >= 400) {
        throw new RuntimeException('Embedding 接口错误：' . $response);
    }

    return $data['data'][0]['embedding'] ?? [];
}
