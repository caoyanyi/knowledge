<?php

header('Content-Type: application/json; charset=utf-8');

$envPath = __DIR__ . '/.env';

if (!file_exists($envPath)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => '缺少 .env 配置文件',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$env = parse_ini_file($envPath);

$baseUrl = $env['OPENAI_BASE_URL'] ?? 'https://api.openai.com';
$apiKey = $env['OPENAI_API_KEY'] ?? '';
$model = $env['OPENAI_MODEL'] ?? '';

if (!$apiKey || !$model) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => '请先配置 OPENAI_API_KEY 和 OPENAI_MODEL',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody, true);

$message = trim($body['message'] ?? '');

if ($message === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'message 不能为空',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = [
    'model' => $model,
    'instructions' => '你是一个企业AI知识库客服助手。回答要清晰、简洁、适合企业客户理解。当前阶段没有接入知识库，所以如果用户问具体企业资料，你要说明需要先上传资料。',
    'input' => $message,
];

$ch = curl_init($baseUrl . '/v1/responses');

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
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => '请求模型失败：' . curl_error($ch),
    ], JSON_UNESCAPED_UNICODE);
    curl_close($ch);
    exit;
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($response, true);

if ($httpCode >= 400) {
    http_response_code($httpCode);
    echo json_encode([
        'ok' => false,
        'error' => '模型接口返回错误',
        'detail' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$answer = $data['output_text'] ?? '';

if ($answer === '' && isset($data['output'])) {
    foreach ($data['output'] as $item) {
        foreach (($item['content'] ?? []) as $content) {
            if (($content['type'] ?? '') === 'output_text') {
                $answer .= $content['text'] ?? '';
            }
        }
    }
}

echo json_encode([
    'ok' => true,
    'answer' => $answer,
], JSON_UNESCAPED_UNICODE);
