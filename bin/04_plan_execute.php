<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use DemoAgent\Agent\AgentLoop;
use DemoAgent\Cli\DemoOptions;
use DemoAgent\Llm\LlmFactory;
use DemoAgent\Tools\ToolRegistry;
use DemoAgent\Tools\WorkspaceTools;

$workspace = project_path('var/workspaces/plan-execute');

try {
    $options = DemoOptions::fromArgv($argv, '在工作区制作一份 agent-notes.md，先列提纲，再写三种 Agent 架构，最后检查文件。');
    $task = $options->task();
    $llm = LlmFactory::forProfile($options->profile(), '04-plan-execute');
    $tools = new ToolRegistry($llm->logger());
    WorkspaceTools::register($tools, $workspace);

    $planMessage = $llm->complete([
        [
            'role' => 'system',
            'content' => '你是 Planner。把任务拆成 2 到 5 个可执行、可验证的步骤。只输出 JSON：{"tasks":["步骤1","步骤2"]}。',
        ],
        ['role' => 'user', 'content' => $task],
    ], [], ['temperature' => 0], 'plan');

    $rawPlan = trim((string) ($planMessage['content'] ?? ''));
    $rawPlan = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $rawPlan) ?? $rawPlan;
    $plan = json_decode($rawPlan, true);
    $tasks = is_array($plan['tasks'] ?? null) ? array_slice($plan['tasks'], 0, 5) : [];
    if ($tasks === []) {
        throw new RuntimeException("Planner 未返回合法任务列表：{$rawPlan}");
    }

    $results = [];
    foreach ($tasks as $index => $step) {
        $executor = new AgentLoop($llm, $tools, maxSteps: 8);
        $results[] = $executor->run(
            "总目标：{$task}\n当前步骤：" . ($index + 1) . ". {$step}\n只完成当前步骤并验证。",
            '你是 Executor。使用工具完成分配给你的单个步骤；不要自行改写总计划。',
        );
    }

    $final = $llm->complete([
        ['role' => 'system', 'content' => '你是结果汇总器。根据计划和各步骤结果回答用户，明确完成项和验证情况。'],
        [
            'role' => 'user',
            'content' => json_encode(
                ['goal' => $task, 'plan' => $tasks, 'results' => $results],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
        ],
    ], [], [], 'plan.finalize');

    echo ($final['content'] ?? '[没有最终回答]') . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, "错误：{$error->getMessage()}\n");
    exit(1);
}
