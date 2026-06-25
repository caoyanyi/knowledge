<?php

require_once __DIR__ . '/app_helper.php';

const STREAM_SOURCES_MARKER = '__SOURCES_JSON__:';

function splitTextIntoChunks(string $text, int $maxLength = 800, int $overlap = 100): array
{
    $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

    if ($text === '') {
        return [];
    }

    $paragraphs = preg_split("/\n{2,}/", $text);
    $chunks = [];
    $current = '';

    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);

        if ($paragraph === '') {
            continue;
        }

        if (mb_strlen($paragraph) > $maxLength) {
            if ($current !== '') {
                $chunks[] = trim($current);
                $current = '';
            }

            $start = 0;
            $length = mb_strlen($paragraph);

            while ($start < $length) {
                $chunks[] = trim(mb_substr($paragraph, $start, $maxLength));
                $start += max(1, $maxLength - $overlap);
            }

            continue;
        }

        $candidate = $current === ''
            ? $paragraph
            : $current . "\n\n" . $paragraph;

        if (mb_strlen($candidate) > $maxLength) {
            $chunks[] = trim($current);

            // Keep a small tail overlap so a split paragraph does not lose context.
            $tail = mb_substr($current, max(0, mb_strlen($current) - $overlap));
            $current = trim($tail . "\n\n" . $paragraph);
        } else {
            $current = $candidate;
        }
    }

    if (trim($current) !== '') {
        $chunks[] = trim($current);
    }

    return array_values(array_filter($chunks));
}

function createEmbedding(array $env, string $text): array
{
    requireEnvKeys($env, ['EMBEDDING_API_KEY']);

    $baseUrl = envString($env, 'EMBEDDING_BASE_URL', 'https://api.openai.com');
    $endpoint = normalizeApiEndpoint($baseUrl, 'embeddings');
    $timeout = envInt($env, 'EMBEDDING_TIMEOUT_SECONDS', 60, 1);

    $response = requestJson(
        'POST',
        $endpoint,
        [
            'model' => envString($env, 'EMBEDDING_MODEL', 'text-embedding-3-small'),
            'input' => $text,
        ],
        [
            'Authorization: Bearer ' . envString($env, 'EMBEDDING_API_KEY'),
        ],
        $timeout
    );

    return $response['json']['data'][0]['embedding'] ?? [];
}

function qdrantCollectionUrl(array $env, string $suffix = ''): string
{
    $qdrantUrl = rtrim(envString($env, 'QDRANT_URL', 'http://127.0.0.1:6333'), '/');
    $collection = envString($env, 'QDRANT_COLLECTION', 'knowledge_chunks');

    return $qdrantUrl . '/collections/' . rawurlencode($collection) . $suffix;
}

function createQdrantCollection(array $env): array
{
    $timeout = envInt($env, 'QDRANT_TIMEOUT_SECONDS', 60, 1);

    return requestJson(
        'PUT',
        qdrantCollectionUrl($env),
        [
            'vectors' => [
                'size' => envInt($env, 'QDRANT_VECTOR_SIZE', 4096, 1),
                'distance' => envString($env, 'QDRANT_DISTANCE', 'Cosine'),
            ],
        ],
        [],
        $timeout
    );
}

function buildKnowledgePoint(int $id, string $title, string $content, string $source, array $vector): array
{
    return [
        'id' => $id,
        'vector' => $vector,
        'payload' => [
            'chunk_id' => $id,
            'title' => $title,
            'content' => $content,
            'source' => $source,
        ],
    ];
}

function upsertQdrantPoints(array $env, array $points): array
{
    if (!$points) {
        return [
            'status' => 200,
            'body' => '',
            'json' => [],
        ];
    }

    $timeout = envInt($env, 'QDRANT_UPSERT_TIMEOUT_SECONDS', 120, 1);

    return requestJson(
        'PUT',
        qdrantCollectionUrl($env, '/points?wait=true'),
        [
            'points' => $points,
        ],
        [],
        $timeout
    );
}

function searchKnowledgeByVector(array $env, string $query, ?int $limit = null): array
{
    $limit = $limit ?? envInt($env, 'KNOWLEDGE_SEARCH_LIMIT', 3, 1);
    $vector = createEmbedding($env, $query);
    $payload = [
        'vector' => $vector,
        'limit' => $limit,
        'with_payload' => true,
        'with_vector' => false,
    ];

    $scoreThreshold = envFloat($env, 'QDRANT_SCORE_THRESHOLD', 0.45, 0.0);

    if ($scoreThreshold > 0) {
        $payload['score_threshold'] = $scoreThreshold;
    }

    $response = requestJson(
        'POST',
        qdrantCollectionUrl($env, '/points/search'),
        $payload,
        [],
        envInt($env, 'QDRANT_TIMEOUT_SECONDS', 60, 1)
    );

    return array_map(function ($item) {
        $payload = $item['payload'] ?? [];

        return [
            'id' => $item['id'] ?? null,
            'score' => $item['score'] ?? 0,
            'title' => $payload['title'] ?? '',
            'content' => $payload['content'] ?? '',
            'source' => $payload['source'] ?? '',
        ];
    }, $response['json']['result'] ?? []);
}

function getRequiredTerms(string $message): array
{
    if (preg_match('/退款|退费|退货|退订|取消订单/u', $message)) {
        return ['退款', '退费', '退货', '退订', '取消订单', '退款流程', '退款条件'];
    }

    if (preg_match('/核心价值|价值/u', $message)) {
        return ['核心价值', '价值', '人工客服', '响应速度', '统一回答口径', '7×24'];
    }

    if (preg_match('/行业|适合/u', $message)) {
        return ['行业', '电商', '教育', '制造业', '医疗健康', '金融保险', '房地产', '企业内部服务'];
    }

    return [];
}

function filterStrongRelatedChunks(array $chunks, string $message): array
{
    $requiredTerms = getRequiredTerms($message);

    if (!$requiredTerms) {
        return $chunks;
    }

    return array_values(array_filter($chunks, function ($chunk) use ($requiredTerms) {
        $text = ($chunk['title'] ?? '') . "\n" . ($chunk['content'] ?? '');

        foreach ($requiredTerms as $term) {
            if (str_contains($text, $term)) {
                return true;
            }
        }

        return false;
    }));
}

function buildKnowledgeContext(array $env, string $message): array
{
    $chunks = searchKnowledgeByVector($env, $message);

    if (envBool($env, 'KNOWLEDGE_STRICT_TERM_FILTER', false)) {
        $chunks = filterStrongRelatedChunks($chunks, $message);
    }

    $context = '';
    $sources = [];

    foreach ($chunks as $index => $chunk) {
        $num = $index + 1;
        $score = number_format((float) $chunk['score'], 4);

        $context .= "资料{$num}：{$chunk['title']}（相关度：{$score}）\n";
        $context .= "{$chunk['content']}\n";
        $context .= "来源：{$chunk['source']}\n\n";

        $sources[] = [
            'title' => $chunk['title'],
            'source' => $chunk['source'],
            'score' => round((float) $chunk['score'], 4),
        ];
    }

    return [
        'context' => $context,
        'sources' => $sources,
        'chunks' => $chunks,
    ];
}

function buildKnowledgePrompt(array $env, string $message, string $knowledgeContext): string
{
    if ($knowledgeContext !== '') {
        return "请基于以下企业资料回答用户问题。\n\n"
            . "【企业资料】\n{$knowledgeContext}\n"
            . "【用户问题】\n{$message}";
    }

    $emptyReply = envString($env, 'KNOWLEDGE_EMPTY_REPLY', '当前知识库资料不足，建议补充相关资料');

    return "知识库没有检索到与用户问题相关的企业资料。请严格只回答：{$emptyReply}。用户问题：{$message}";
}
