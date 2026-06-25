<?php

require __DIR__ . '/embedding_helper.php';

$env = parse_ini_file(__DIR__ . '/.env');

$pdo = new PDO(
    "mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_DATABASE']};charset=utf8mb4",
    $env['DB_USERNAME'],
    $env['DB_PASSWORD'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$qdrantUrl = rtrim($env['QDRANT_URL'] ?? 'http://127.0.0.1:6333', '/');
$collection = $env['QDRANT_COLLECTION'] ?? 'knowledge_chunks';

$rows = $pdo->query("
    SELECT id, title, content, source
    FROM knowledge_chunks
    ORDER BY id ASC
")->fetchAll();

$points = [];

foreach ($rows as $row) {
    $textForEmbedding = $row['title'] . "\n" . $row['content'];
    echo "生成向量：#{$row['id']} {$row['title']}\n";

    $vector = createEmbedding($env, $textForEmbedding);

    $points[] = [
        'id' => (int) $row['id'],
        'vector' => $vector,
        'payload' => [
            'chunk_id' => (int) $row['id'],
            'title' => $row['title'],
            'content' => $row['content'],
            'source' => $row['source'],
        ],
    ];
}

$payload = [
    'points' => $points,
];

$ch = curl_init($qdrantUrl . '/collections/' . $collection . '/points?wait=true');

curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => 'PUT',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 120,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Qdrant HTTP: {$httpCode}\n";
echo $response . "\n";
