<?php

declare(strict_types=1);

namespace DemoAgent\Context;

use DemoAgent\Llm\LlmClient;

final class ContextManager
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly int $tokenBudget = 12_000,
        private readonly int $maxOldToolChars = 1_200,
        private readonly int $keepRecentMessages = 6,
    ) {
    }

    /**
     * 组合两种压缩：先清理可重新获取的旧工具输出，再用 LLM 总结旧轨迹。
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    public function prepare(array $messages): array
    {
        return $this->manage($messages, false);
    }

    /**
     * 用户主动压缩时忽略 Token 阈值，但仍保留最近消息。
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    public function forceCompact(array $messages): array
    {
        return $this->manage($messages, true);
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    private function manage(array $messages, bool $force): array
    {
        $cleared = 0;
        $oldBoundary = max(0, count($messages) - $this->keepRecentMessages);
        foreach ($messages as $index => &$message) {
            if (
                $index < $oldBoundary
                && ($message['role'] ?? null) === 'tool'
                && is_string($message['content'] ?? null)
                && strlen($message['content']) > $this->maxOldToolChars
            ) {
                $message['content'] = '[旧工具输出已清理；该数据可通过重新调用工具获取]';
                $cleared++;
            }
        }
        unset($message);

        $estimatedTokens = $this->estimateTokens($messages);
        if ($cleared > 0) {
            $this->llm->logger()->record('context.tool_results_cleared', [
                'count' => $cleared,
                'estimated_tokens_after' => $estimatedTokens,
            ]);
        }

        if (
            (!$force && $estimatedTokens <= $this->tokenBudget)
            || count($messages) <= $this->keepRecentMessages + 1
        ) {
            return $messages;
        }

        $cut = count($messages) - $this->keepRecentMessages;
        while ($cut > 1 && ($messages[$cut]['role'] ?? null) === 'tool') {
            $cut--;
        }

        $oldMessages = array_slice($messages, 1, $cut - 1);
        if ($oldMessages === []) {
            return $messages;
        }

        $summaryResponse = $this->llm->complete([
            [
                'role' => 'system',
                'content' => '你是上下文压缩器。高召回地保留：目标、已完成工作、关键事实、文件路径、工具结果结论、失败尝试、约束和未解决事项。删除重复对话和可重新获取的大段原文。只输出结构化中文摘要。',
            ],
            [
                'role' => 'user',
                'content' => json_encode(
                    $oldMessages,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_INVALID_UTF8_SUBSTITUTE
                    | JSON_THROW_ON_ERROR,
                ),
            ],
        ], [], ['temperature' => 0], 'context.compaction');

        $summary = is_string($summaryResponse['content'] ?? null)
            ? $summaryResponse['content']
            : '旧轨迹摘要生成失败。';
        $compacted = [
            $messages[0],
            ['role' => 'system', 'content' => "此前执行轨迹摘要：\n{$summary}"],
            ...array_slice($messages, $cut),
        ];

        $this->llm->logger()->record('context.compacted', [
            'messages_before' => count($messages),
            'messages_after' => count($compacted),
            'estimated_tokens_before' => $estimatedTokens,
            'estimated_tokens_after' => $this->estimateTokens($compacted),
        ]);

        return $compacted;
    }

    /** @param list<array<string, mixed>> $messages */
    private function estimateTokens(array $messages): int
    {
        $json = json_encode(
            $messages,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return (int) ceil(strlen($json === false ? '' : $json) / 3.2);
    }
}
