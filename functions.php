<?php
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
