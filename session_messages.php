<?php

header('Content-Type: application/json; charset=utf-8');

$sessionId = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['session_id'] ?? ''), 0, 64);

if ($sessionId === '') {
    echo json_encode(['ok' => false, 'error' => 'session_id 不能为空'], JSON_UNESCAPED_UNICODE);
    exit;
}

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

$stmt = $pdo->prepare("
    SELECT user_message, assistant_answer
    FROM chat_logs
    WHERE session_id = :session_id
    ORDER BY id ASC
");

$stmt->execute([':session_id' => $sessionId]);

echo json_encode([
    'ok' => true,
    'messages' => $stmt->fetchAll(),
], JSON_UNESCAPED_UNICODE);
