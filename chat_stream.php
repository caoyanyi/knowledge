<?php

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

require_once __DIR__ . '/qdrant_helper.php';
require_once __DIR__ . '/functions.php';

set_time_limit(0);

while (ob_get_level() > 0) {
    ob_end_flush();
}

$envPath = __DIR__ . '/.env';

if (!file_exists($envPath)) {
    http_response_code(500);
    echo "缺少 .env 配置文件";
    exit;
}

$env = parse_ini_file($envPath);

$apiKey = $env['OPENAI_API_KEY'] ?? '';
$model = $env['OPENAI_MODEL'] ?? '';
$baseUrl = rtrim($env['OPENAI_BASE_URL'] ?? 'https://api.openai.com', '/');

if (!$apiKey || !$model) {
    http_response_code(500);
    echo "请先配置 OPENAI_API_KEY 和 OPENAI_MODEL";
    exit;
}

$dbHost = $env['DB_HOST'] ?? '127.0.0.1';
$dbPort = $env['DB_PORT'] ?? '3306';
$dbName = $env['DB_DATABASE'] ?? '';
$dbUser = $env['DB_USERNAME'] ?? '';
$dbPass = $env['DB_PASSWORD'] ?? '';

$pdo = null;
if ($dbName && $dbUser) {
    try {
        $pdo = new PDO(
            "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
            $dbUser,
            $dbPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    } catch (Throwable $e) {
        $pdo = null;
    }
}

$endpoint = str_ends_with($baseUrl, '/v1')
    ? $baseUrl . '/responses'
    : $baseUrl . '/v1/responses';

$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody, true);

$message = trim($body['message'] ?? '');
$sessionId = trim($body['session_id'] ?? '');

if ($sessionId === '') {
    $sessionId = 'sess_' . bin2hex(random_bytes(16));
}

$sessionId = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', $sessionId), 0, 64);

if ($message === '') {
    http_response_code(400);
    echo "message 不能为空";
    exit;
}

$startedAt = microtime(true);
$fullAnswer = '';

$knowledgeContext = '';
$knowledgeSources = [];

if ($message !== '') {
    try {
        $chunks = searchKnowledgeByVector($env, $message, 3);

        foreach ($chunks as $index => $chunk) {
            $num = $index + 1;
            $score = number_format((float) $chunk['score'], 4);

            $knowledgeContext .= "资料{$num}：{$chunk['title']}（相关度：{$score}）\n";
            $knowledgeContext .= "{$chunk['content']}\n";
            $knowledgeContext .= "来源：{$chunk['source']}\n\n";

            $knowledgeSources[] = $chunk['title'];
        }
    } catch (Throwable $e) {
        $knowledgeContext = '';
        $knowledgeSources = [];
    }
}

$historyItems = [];

if ($pdo && $sessionId !== '') {
    try {
        $stmt = $pdo->prepare("
            SELECT user_message, assistant_answer
            FROM chat_logs
            WHERE session_id = :session_id
            ORDER BY id DESC
            LIMIT 50
        ");

        $stmt->execute([
            ':session_id' => $sessionId,
        ]);

        $rows = array_reverse($stmt->fetchAll());

        foreach ($rows as $row) {
            $historyItems[] = [
                'role' => 'user',
                'content' => $row['user_message'],
            ];

            $historyItems[] = [
                'role' => 'assistant',
                'content' => $row['assistant_answer'],
            ];
        }
    } catch (Throwable $e) {
        $historyItems = [];
    }
}

$userContent = $message;
if ($knowledgeContext !== '') {
    $userContent = "请基于以下企业资料回答用户问题。\n\n";
    $userContent .= "【企业资料】\n{$knowledgeContext}\n";
    $userContent .= "【用户问题】\n{$message}";
} else {
    $userContent = "知识库没有检索到与用户问题相关的企业资料。请严格只回答：当前知识库资料不足，建议补充相关资料。用户问题：{$message}";
}

$inputMessages = $knowledgeContext !== ''
    ? array_merge($historyItems, [
        [
            'role' => 'user',
            'content' => $userContent,
        ],
    ])
    : [
        [
            'role' => 'user',
            'content' => $userContent,
        ],
    ];

$payload = [
    'model' => $model,
    'instructions' => '你是企业AI知识库客服助手。必须优先基于提供的企业资料回答。资料不足时要明确说明“当前知识库资料不足，建议补充相关资料”，不要编造企业信息。回答简洁清晰。',
    'input' => $inputMessages,
    'stream' => true,
    'max_output_tokens' => 4096,
];

$ch = curl_init($endpoint);

$buffer = '';

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'Accept: text/event-stream',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 0,
    CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buffer, &$fullAnswer) {
        $buffer .= $chunk;
        $buffer = str_replace("\r\n", "\n", $buffer);

        while (($pos = strpos($buffer, "\n\n")) !== false) {
            $eventBlock = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 2);

            $lines = explode("\n", $eventBlock);

            foreach ($lines as $line) {
                $line = trim($line);

                if (!str_starts_with($line, 'data:')) {
                    continue;
                }

                $json = trim(substr($line, 5));

                if ($json === '' || $json === '[DONE]') {
                    continue;
                }

                $event = json_decode($json, true);

                if (!is_array($event)) {
                    continue;
                }

                if (($event['type'] ?? '') === 'response.output_text.delta') {
                    $delta = $event['delta'] ?? '';
                    $fullAnswer .= $delta;
                    echo $delta;
                    flush();
                }

                if (($event['type'] ?? '') === 'error') {
                    echo "\n[模型错误] " . ($event['message'] ?? '未知错误');
                    flush();
                }
            }
        }

        return strlen($chunk);
    },
]);

$result = curl_exec($ch);

if ($result === false) {
    echo "\n[请求失败] " . curl_error($ch);
}

curl_close($ch);

if ($knowledgeSources) {
    $sourcePayload = [
        'sources' => $knowledgeSources,
    ];

    echo "\n__SOURCES_JSON__:" . json_encode($sourcePayload, JSON_UNESCAPED_UNICODE);
    flush();
}

// 记录日志到数据库
$requestTimeMs = (int) round((microtime(true) - $startedAt) * 1000);

if ($pdo && $message !== '' && $fullAnswer !== '') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO chat_logs
                (session_id, user_message, assistant_answer, model, request_time_ms)
            VALUES
                (:session_id, :user_message, :assistant_answer, :model, :request_time_ms)
        ");

        $stmt->execute([
            ':session_id' => $sessionId,
            ':user_message' => $message,
            ':assistant_answer' => $fullAnswer,
            ':model' => $model,
            ':request_time_ms' => $requestTimeMs,
        ]);
    } catch (Throwable $e) {
        // 当前阶段不打断用户回答，后面再接错误日志。
    }
}
