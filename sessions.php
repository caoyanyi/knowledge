<?php

header('Content-Type: application/json; charset=utf-8');

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

$stmt = $pdo->query("
    SELECT
        session_id,
        MIN(user_message) AS title,
        COUNT(*) AS message_count,
        MAX(created_at) AS last_time
    FROM chat_logs
    WHERE session_id <> ''
    GROUP BY session_id
    ORDER BY last_time DESC
    LIMIT 20
");

echo json_encode([
    'ok' => true,
    'sessions' => $stmt->fetchAll(),
], JSON_UNESCAPED_UNICODE);
