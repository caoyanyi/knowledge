<?php

/**
 * 读取项目配置，默认从项目根目录的 .env 加载。
 */
function loadEnv(?string $path = null): array
{
    $envPath = $path ?? dirname(__DIR__) . '/.env';

    if (!file_exists($envPath)) {
        throw new RuntimeException('缺少 .env 配置文件');
    }

    $env = parse_ini_file($envPath);

    if (!is_array($env)) {
        throw new RuntimeException('.env 配置文件解析失败');
    }

    return $env;
}

/**
 * 读取字符串配置，统一做 trim，避免空格影响后续判断。
 */
function envString(array $env, string $key, string $default = ''): string
{
    return trim((string) ($env[$key] ?? $default));
}

/**
 * 读取整数配置，并通过最小值约束过滤明显不合法的配置。
 */
function envInt(array $env, string $key, int $default, int $min = 0): int
{
    $value = (int) ($env[$key] ?? $default);

    return max($min, $value);
}

/**
 * 读取浮点数配置，常用于阈值、相似度这类可调参数。
 */
function envFloat(array $env, string $key, float $default, float $min = 0.0): float
{
    $value = (float) ($env[$key] ?? $default);

    return max($min, $value);
}

/**
 * 兼容常见布尔配置写法，例如 true/1/yes/on。
 */
function envBool(array $env, string $key, bool $default = false): bool
{
    $value = strtolower(envString($env, $key, $default ? 'true' : 'false'));

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

/**
 * 校验接口调用必须依赖的配置项，缺失时给出明确提示。
 */
function requireEnvKeys(array $env, array $keys): void
{
    $missing = [];

    foreach ($keys as $key) {
        if (envString($env, $key) === '') {
            $missing[] = $key;
        }
    }

    if ($missing) {
        throw new RuntimeException('请先配置 ' . implode('、', $missing));
    }
}

/**
 * 返回统一 JSON 响应，并在输出后结束当前请求。
 */
function sendJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 返回统一错误结构，方便前端用 ok/error 字段处理失败状态。
 */
function sendJsonError(string $message, int $statusCode = 500, array $extra = []): void
{
    sendJson(array_merge([
        'ok' => false,
        'error' => $message,
    ], $extra), $statusCode);
}

/**
 * 解析 JSON 请求体；空请求体按空数组处理，便于 GET/空 POST 复用。
 */
function readJsonBody(): array
{
    $rawBody = file_get_contents('php://input');

    if (trim((string) $rawBody) === '') {
        return [];
    }

    $body = json_decode($rawBody, true);

    return is_array($body) ? $body : [];
}

/**
 * 清洗会话 ID，避免用户输入进入 SQL 或路径相关逻辑。
 */
function sanitizeSessionId(string $sessionId): string
{
    return substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', $sessionId), 0, 64);
}

/**
 * 创建浏览器会话使用的随机会话 ID。
 */
function createSessionId(): string
{
    return 'sess_' . bin2hex(random_bytes(16));
}

/**
 * 创建 MySQL 连接；非必需场景下失败可返回 null，让问答流程继续执行。
 */
function createPdo(array $env, bool $required = true): ?PDO
{
    $dbHost = envString($env, 'DB_HOST', '127.0.0.1');
    $dbPort = envString($env, 'DB_PORT', '3306');
    $dbName = envString($env, 'DB_DATABASE');
    $dbUser = envString($env, 'DB_USERNAME');
    $dbPass = envString($env, 'DB_PASSWORD');

    if ($dbName === '' || $dbUser === '') {
        if ($required) {
            throw new RuntimeException('请先配置 DB_DATABASE 和 DB_USERNAME');
        }

        return null;
    }

    try {
        return new PDO(
            "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
            $dbUser,
            $dbPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    } catch (Throwable $e) {
        if ($required) {
            throw $e;
        }

        return null;
    }
}

/**
 * 将可配置的服务根地址规范化为 /v1/{resource} 接口地址。
 */
function normalizeApiEndpoint(string $baseUrl, string $resource): string
{
    $baseUrl = rtrim($baseUrl, '/');
    $resource = ltrim($resource, '/');

    if (str_ends_with($baseUrl, '/v1')) {
        return $baseUrl . '/' . $resource;
    }

    return $baseUrl . '/v1/' . $resource;
}

/**
 * 发送 JSON HTTP 请求，封装 cURL 错误、HTTP 状态码和 JSON 解析结果。
 */
function requestJson(string $method, string $url, array $payload, array $headers = [], int $timeout = 60): array
{
    $ch = curl_init($url);
    $method = strtoupper($method);
    $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $requestHeaders = array_merge([
        'Content-Type: application/json',
    ], $headers);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_POSTFIELDS => $encodedPayload,
        CURLOPT_TIMEOUT => $timeout,
    ];

    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
    } else {
        $options[CURLOPT_CUSTOMREQUEST] = $method;
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP 请求失败：' . $error);
    }

    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($response, true);

    if ($statusCode >= 400) {
        throw new RuntimeException("HTTP {$statusCode}: {$response}");
    }

    return [
        'status' => $statusCode,
        'body' => $response,
        'json' => is_array($json) ? $json : [],
    ];
}

/**
 * 模型默认系统指令，约束回答优先基于企业知识库。
 */
function defaultAssistantInstructions(): string
{
    return '你是企业AI知识库客服助手。必须优先基于提供的企业资料回答。资料不足时要明确说明“当前知识库资料不足，建议补充相关资料”，不要编造企业信息。回答简洁清晰。';
}

/**
 * 获取 OpenAI-compatible Responses API 地址。
 */
function openAiResponsesEndpoint(array $env): string
{
    $baseUrl = envString($env, 'OPENAI_BASE_URL', 'https://api.openai.com');

    return normalizeApiEndpoint($baseUrl, 'responses');
}

/**
 * 构造模型请求头，流式请求时额外声明接收 SSE。
 */
function openAiRequestHeaders(array $env, bool $stream = false): array
{
    requireEnvKeys($env, ['OPENAI_API_KEY', 'OPENAI_MODEL']);

    $headers = [
        'Authorization: Bearer ' . envString($env, 'OPENAI_API_KEY'),
    ];

    if ($stream) {
        $headers[] = 'Accept: text/event-stream';
    }

    return $headers;
}

/**
 * 构造 Responses API 请求体，支持普通文本输入和多轮 messages 输入。
 */
function buildResponsesPayload(array $env, array|string $input, bool $stream = false): array
{
    requireEnvKeys($env, ['OPENAI_API_KEY', 'OPENAI_MODEL']);

    $payload = [
        'model' => envString($env, 'OPENAI_MODEL'),
        'instructions' => envString($env, 'OPENAI_INSTRUCTIONS', defaultAssistantInstructions()),
        'input' => $input,
        'max_output_tokens' => envInt($env, 'OPENAI_MAX_OUTPUT_TOKENS', 4096, 1),
    ];

    if ($stream) {
        $payload['stream'] = true;
    }

    return $payload;
}

/**
 * 调用非流式模型接口，返回解析后的 JSON 数据。
 */
function callOpenAiResponse(array $env, array $payload): array
{
    $timeout = envInt($env, 'OPENAI_TIMEOUT_SECONDS', 60, 1);
    $response = requestJson(
        'POST',
        openAiResponsesEndpoint($env),
        $payload,
        openAiRequestHeaders($env),
        $timeout
    );

    return $response['json'];
}

/**
 * 调用流式模型接口，将每个文本增量交给回调实时输出。
 */
function streamOpenAiResponse(array $env, array $payload, callable $onTextDelta, ?callable $onError = null): bool
{
    $ch = curl_init(openAiResponsesEndpoint($env));
    $buffer = '';
    $rawResponse = '';
    $receivedDelta = false;

    // Responses API 使用 SSE；网络分片可能截断事件，所以先缓存到空行分隔符再解析。
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HTTPHEADER => openAiRequestHeaders($env, true),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => envInt($env, 'OPENAI_STREAM_TIMEOUT_SECONDS', 0, 0),
        CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buffer, &$rawResponse, &$receivedDelta, $onTextDelta, $onError) {
            $rawResponse .= $chunk;
            $buffer .= str_replace("\r\n", "\n", $chunk);

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
                        $receivedDelta = true;
                        $onTextDelta((string) ($event['delta'] ?? ''));
                    } elseif (($event['type'] ?? '') === 'error' && $onError) {
                        $onError((string) ($event['message'] ?? '未知错误'));
                    }
                }
            }

            return strlen($chunk);
        },
    ]);

    $result = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($result === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('请求模型失败：' . $error);
    }

    curl_close($ch);

    if ($statusCode >= 400) {
        if ($onError && envBool($env, 'OPENAI_STREAM_REPORT_UPSTREAM_ERROR', false)) {
            $json = json_decode($rawResponse, true);
            $message = $json['error']['message'] ?? $rawResponse;
            $onError("HTTP {$statusCode}: {$message}");
        }

        return false;
    }

    return $receivedDelta;
}

/**
 * 从 Responses API 结果中提取最终文本，兼容 output_text 和 output.content 两种结构。
 */
function extractResponseText(array $data): string
{
    $answer = (string) ($data['output_text'] ?? '');

    if ($answer !== '' || !isset($data['output'])) {
        return $answer;
    }

    foreach ($data['output'] as $item) {
        foreach (($item['content'] ?? []) as $content) {
            if (($content['type'] ?? '') === 'output_text') {
                $answer .= (string) ($content['text'] ?? '');
            }
        }
    }

    return $answer;
}

/**
 * 加载最近的多轮上下文，按 user/assistant 交替格式返回给模型。
 */
function fetchChatHistory(PDO $pdo, string $sessionId, int $limit): array
{
    $stmt = $pdo->prepare("
        SELECT user_message, assistant_answer
        FROM chat_logs
        WHERE session_id = :session_id
        ORDER BY id DESC
        LIMIT {$limit}
    ");

    $stmt->execute([
        ':session_id' => $sessionId,
    ]);

    $messages = [];

    // SQL 为了 limit 取最近记录使用倒序，传给模型前恢复为时间正序。
    foreach (array_reverse($stmt->fetchAll()) as $row) {
        $messages[] = [
            'role' => 'user',
            'content' => $row['user_message'],
        ];

        $messages[] = [
            'role' => 'assistant',
            'content' => $row['assistant_answer'],
        ];
    }

    return $messages;
}

/**
 * 保存一次问答日志，用于历史会话列表和后续上下文拼接。
 */
function saveChatLog(PDO $pdo, string $sessionId, string $message, string $answer, string $model, int $requestTimeMs): void
{
    $stmt = $pdo->prepare("
        INSERT INTO chat_logs
            (session_id, user_message, assistant_answer, model, request_time_ms)
        VALUES
            (:session_id, :user_message, :assistant_answer, :model, :request_time_ms)
    ");

    $stmt->execute([
        ':session_id' => $sessionId,
        ':user_message' => $message,
        ':assistant_answer' => $answer,
        ':model' => $model,
        ':request_time_ms' => $requestTimeMs,
    ]);
}

/**
 * 获取最近会话摘要，供侧边栏快速恢复历史对话。
 */
function fetchRecentSessions(PDO $pdo, int $limit): array
{
    return $pdo->query("
        SELECT
            session_id,
            MIN(user_message) AS title,
            COUNT(*) AS message_count,
            MAX(created_at) AS last_time
        FROM chat_logs
        WHERE session_id <> ''
        GROUP BY session_id
        ORDER BY last_time DESC
        LIMIT {$limit}
    ")->fetchAll();
}

/**
 * 获取指定会话的完整消息列表。
 */
function fetchSessionMessages(PDO $pdo, string $sessionId): array
{
    $stmt = $pdo->prepare("
        SELECT user_message, assistant_answer
        FROM chat_logs
        WHERE session_id = :session_id
        ORDER BY id ASC
    ");

    $stmt->execute([
        ':session_id' => $sessionId,
    ]);

    return $stmt->fetchAll();
}
