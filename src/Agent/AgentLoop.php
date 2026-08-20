<?php

declare(strict_types=1);

namespace DemoAgent\Agent;

use DemoAgent\Context\ContextManager;
use DemoAgent\Llm\LlmClient;
use DemoAgent\Tools\ToolRegistry;

final class AgentLoop
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly ToolRegistry $tools,
        private readonly ?ContextManager $contextManager = null,
        private readonly int $maxSteps = 12,
        private readonly string $purposePrefix = 'agent.step',
    ) {
        if ($this->maxSteps < 1) {
            throw new \InvalidArgumentException('Agent maxSteps 必须大于 0');
        }
        if ($this->purposePrefix === '') {
            throw new \InvalidArgumentException('Agent purposePrefix 不能为空');
        }
    }

    public function run(string $task, string $systemPrompt): string
    {
        /** @var list<array<string, mixed>> $messages */
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $task],
        ];

        for ($step = 1; $step <= $this->maxSteps; $step++) {
            if ($this->contextManager !== null) {
                $messages = $this->contextManager->prepare($messages);
            }

            $assistant = $this->llm->complete(
                $messages,
                $this->tools->schemas(),
                [],
                "{$this->purposePrefix}.{$step}",
            );
            $messages[] = $assistant;

            $toolCalls = $assistant['tool_calls'] ?? [];
            if (!is_array($toolCalls) || $toolCalls === []) {
                return is_string($assistant['content'] ?? null)
                    ? $assistant['content']
                    : '任务结束，但模型没有返回文本结果。';
            }

            foreach ($toolCalls as $toolCall) {
                if (!is_array($toolCall)) {
                    continue;
                }
                $function = $toolCall['function'] ?? [];
                $name = is_array($function) ? (string) ($function['name'] ?? '') : '';
                $rawArguments = is_array($function) ? ($function['arguments'] ?? '{}') : '{}';
                $arguments = $this->decodeArguments($rawArguments);
                $content = $this->tools->execute($name, $arguments);
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($toolCall['id'] ?? "call_{$step}"),
                    'content' => $content,
                ];
            }
        }

        throw new \RuntimeException("达到最大循环次数 {$this->maxSteps}，Agent 未正常结束");
    }

    /** @return array<string, mixed> */
    private function decodeArguments(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
