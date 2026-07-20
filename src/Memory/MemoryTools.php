<?php

declare(strict_types=1);

namespace DemoAgent\Memory;

use DemoAgent\Tools\CallableTool;
use DemoAgent\Tools\ToolRegistry;

final class MemoryTools
{
    public static function register(ToolRegistry $registry, MemoryStore $memory): void
    {
        $registry->register(new CallableTool(
            'remember',
            '把未来任务仍有价值的事实、偏好、决策或经验写入外部长期记忆。不要保存临时工具输出。',
            [
                'type' => 'object',
                'properties' => [
                    'content' => ['type' => 'string'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['content'],
                'additionalProperties' => false,
            ],
            static fn (array $arguments): array => $memory->remember(
                (string) ($arguments['content'] ?? ''),
                is_array($arguments['tags'] ?? null) ? $arguments['tags'] : [],
            ),
        ));

        $registry->register(new CallableTool(
            'recall_memory',
            '按关键词检索存放在上下文窗口之外的长期记忆。',
            [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ],
            static fn (array $arguments): array => $memory->search(
                (string) ($arguments['query'] ?? ''),
                (int) ($arguments['limit'] ?? 5),
            ),
        ));
    }
}
