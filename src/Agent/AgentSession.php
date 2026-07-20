<?php

declare(strict_types=1);

namespace DemoAgent\Agent;

use DemoAgent\Context\ContextManager;
use DemoAgent\Llm\LlmClient;
use DemoAgent\Tools\ToolRegistry;

/**
 * 可跨用户输入保留消息历史的有状态 Agent 会话。
 */
final class AgentSession
{
    /** @var list<array<string, mixed>> */
    private array $messages;

    private int $turn = 0;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly ToolRegistry $tools,
        private readonly string $systemPrompt,
        private readonly ?ContextManager $contextManager = null,
        private readonly int $maxStepsPerTurn = 12,
    ) {
        $this->clear();
    }

    public function send(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            throw new \InvalidArgumentException('输入不能为空');
        }

        $this->turn++;
        $this->messages[] = ['role' => 'user', 'content' => $input];

        for ($step = 1; $step <= $this->maxStepsPerTurn; $step++) {
            if ($this->contextManager !== null) {
                $this->messages = $this->contextManager->prepare($this->messages);
            }

            $assistant = $this->llm->complete(
                $this->messages,
                $this->tools->schemas(),
                [],
                "chat.turn.{$this->turn}.step.{$step}",
            );
            $this->messages[] = $assistant;

            $toolCalls = $assistant['tool_calls'] ?? [];
            if (!is_array($toolCalls) || $toolCalls === []) {
                return is_string($assistant['content'] ?? null) && $assistant['content'] !== ''
                    ? $assistant['content']
                    : '本轮结束，但模型没有返回文本。';
            }

            foreach ($toolCalls as $toolCall) {
                if (!is_array($toolCall)) {
                    continue;
                }
                $function = is_array($toolCall['function'] ?? null) ? $toolCall['function'] : [];
                $arguments = $this->decodeArguments($function['arguments'] ?? '{}');
                $content = $this->tools->execute((string) ($function['name'] ?? ''), $arguments);
                $this->messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($toolCall['id'] ?? "chat_{$this->turn}_{$step}"),
                    'content' => $content,
                ];
            }
        }

        throw new \RuntimeException("本轮达到最大循环次数 {$this->maxStepsPerTurn}，Agent 未正常结束");
    }

    public function clear(): void
    {
        $this->messages = [['role' => 'system', 'content' => $this->systemPrompt]];
        $this->turn = 0;
    }

    public function compact(): bool
    {
        if ($this->contextManager === null) {
            return false;
        }

        $before = count($this->messages);
        $this->messages = $this->contextManager->forceCompact($this->messages);

        return count($this->messages) < $before;
    }

    /** @return list<array<string, mixed>> */
    public function messages(): array
    {
        return $this->messages;
    }

    public function turn(): int
    {
        return $this->turn;
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
