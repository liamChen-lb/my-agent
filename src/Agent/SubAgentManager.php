<?php

declare(strict_types=1);

namespace DemoAgent\Agent;

use DemoAgent\Llm\LlmClient;
use DemoAgent\Tools\CallableTool;
use DemoAgent\Tools\DeveloperTools;
use DemoAgent\Tools\ToolRegistry;
use DemoAgent\Tools\WorkspaceTools;

/**
 * 为父 Agent 提供隔离消息历史的同步 Sub-agent。
 *
 * 子 Agent 复用同一个 LLM Client、日志器和文件边界，但拥有独立 messages 和受限工具集；
 * 子工具集中不注册 delegate_task，因此不能递归创建更多 Sub-agent。
 */
final class SubAgentManager
{
    private int $invocations = 0;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly string $workspace,
        private readonly int $maxSteps = 6,
        private readonly int $maxInvocations = 4,
        private readonly int $maxResultChars = 12000,
    ) {
        if ($this->maxSteps < 1 || $this->maxInvocations < 1 || $this->maxResultChars < 100) {
            throw new \InvalidArgumentException('Sub-agent 限额配置无效');
        }
        if (!is_dir($this->workspace)) {
            throw new \InvalidArgumentException("Sub-agent 工作区不存在：{$this->workspace}");
        }
    }

    public function registerTool(ToolRegistry $registry): void
    {
        $registry->register(new CallableTool(
            'delegate_task',
            '把边界清晰、可独立完成的研究或工作区任务委派给隔离上下文的 Sub-agent。'
            . 'research 模式只读；workspace 模式可读写文件但不能运行 Shell；子 Agent 不能继续递归委派。',
            [
                'type' => 'object',
                'properties' => [
                    'task' => [
                        'type' => 'string',
                        'description' => '完整、可独立执行的任务和预期输出',
                        'minLength' => 1,
                        'maxLength' => 8000,
                    ],
                    'mode' => [
                        'type' => 'string',
                        'enum' => ['research', 'workspace'],
                        'description' => 'research 只读；workspace 允许文件写入和精确编辑',
                        'default' => 'research',
                    ],
                    'context' => [
                        'type' => 'string',
                        'description' => '父 Agent 挑选出的最小必要背景；不要复制整段会话',
                        'maxLength' => 8000,
                    ],
                ],
                'required' => ['task'],
                'additionalProperties' => false,
            ],
            fn (array $arguments): array => $this->run($arguments),
        ));
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function run(array $arguments): array
    {
        $task = $this->requiredString($arguments, 'task', 8000);
        $mode = (string) ($arguments['mode'] ?? 'research');
        if (!in_array($mode, ['research', 'workspace'], true)) {
            throw new \InvalidArgumentException('Sub-agent mode 只能是 research 或 workspace');
        }
        $context = $arguments['context'] ?? '';
        if (!is_string($context) || mb_strlen($context) > 8000) {
            throw new \InvalidArgumentException('Sub-agent context 必须是不超过 8000 字符的字符串');
        }
        if ($this->invocations >= $this->maxInvocations) {
            throw new \RuntimeException("本会话最多调用 {$this->maxInvocations} 次 Sub-agent");
        }

        $invocation = ++$this->invocations;
        $logger = $this->llm->logger();
        $logger->record('subagent.started', [
            'invocation' => $invocation,
            'mode' => $mode,
            'task' => $task,
        ]);

        try {
            $tools = new ToolRegistry($logger);
            $writeEnabled = $mode === 'workspace';
            WorkspaceTools::register($tools, $this->workspace, $writeEnabled);
            DeveloperTools::register(
                $tools,
                $this->workspace,
                static fn (string $_command): bool => false,
                shellEnabled: false,
                editEnabled: $writeEnabled,
            );

            $accessRule = $writeEnabled
                ? '你可以在工作区内创建、覆盖或精确编辑文件；写入前先读取相关内容，完成后读回验证。'
                : '你处于只读研究模式，不能创建或修改任何文件。';
            $systemPrompt = <<<PROMPT
你是由父 Agent 临时创建的 Sub-agent，只负责下面这一项边界清晰的任务。

规则：
1. 你的消息历史与父 Agent 隔离，只能依据任务、最小背景和工具 observation 工作。
2. {$accessRule}
3. 你不能执行 Shell，也不能创建其他 Sub-agent。
4. 先检查证据再下结论；不要声称完成未实际执行的动作。
5. 最终只返回给父 Agent 有用的结论、证据、文件路径和未解决风险。

工作区：{$this->workspace}
PROMPT;
            $input = $context === ''
                ? $task
                : "任务：\n{$task}\n\n父 Agent 提供的最小背景：\n{$context}";
            $agent = new AgentLoop(
                $this->llm,
                $tools,
                maxSteps: $this->maxSteps,
                purposePrefix: "subagent.{$invocation}.step",
            );
            $result = $agent->run($input, $systemPrompt);
            $truncated = mb_strlen($result) > $this->maxResultChars;
            if ($truncated) {
                $result = mb_substr($result, 0, $this->maxResultChars) . "\n[Sub-agent 结果已截断]";
            }
            $logger->record('subagent.completed', [
                'invocation' => $invocation,
                'mode' => $mode,
                'result_chars' => mb_strlen($result),
                'truncated' => $truncated,
            ]);

            return [
                'ok' => true,
                'invocation' => $invocation,
                'mode' => $mode,
                'result' => $result,
                'truncated' => $truncated,
            ];
        } catch (\Throwable $error) {
            $logger->record('subagent.failed', [
                'invocation' => $invocation,
                'mode' => $mode,
                'error' => $error->getMessage(),
            ]);
            throw $error;
        }
    }

    /** @param array<string, mixed> $arguments */
    private function requiredString(array $arguments, string $key, int $maxLength): string
    {
        $value = $arguments[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("参数 {$key} 必须是非空字符串");
        }
        if (mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException("参数 {$key} 不能超过 {$maxLength} 字符");
        }

        return $value;
    }
}
