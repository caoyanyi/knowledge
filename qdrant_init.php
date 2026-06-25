<?php

$env = parse_ini_file(__DIR__ . '/.env');

$qdrantUrl = rtrim($env['QDRANT_URL'] ?? 'http://127.0.0.1:6333', '/');
$collection = $env['QDRANT_COLLECTION'] ?? 'knowledge_chunks';

$payload = [
    'vectors' => [
        'size' => 4096,
        'distance' => 'Cosine',
    ],
];

$ch = curl_init($qdrantUrl . '/collections/' . $collection);

curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => 'PUT',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP: {$httpCode}\n";
echo $response . "\n";
