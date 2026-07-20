<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use DemoAgent\Agent\AgentLoop;
use DemoAgent\Cli\DemoOptions;
use DemoAgent\Llm\LlmFactory;
use DemoAgent\Tools\ToolRegistry;
use DemoAgent\Tools\WorkspaceTools;

$workspace = project_path('var/workspaces/react');

try {
    $options = DemoOptions::fromArgv($argv, '创建 note.txt，写下三条“LLM 与 Agent 的区别”，然后读回检查。');
    $llm = LlmFactory::forProfile($options->profile(), '03-react-agent');
    $tools = new ToolRegistry($llm->logger());
    WorkspaceTools::register($tools, $workspace);
    $agent = new AgentLoop($llm, $tools, maxSteps: 10);

    $answer = $agent->run($options->task(), <<<'PROMPT'
你是一个教学用文件 Agent。遵循“观察 → 决策 → 行动 → 再观察”的循环。
先获取必要信息；每次工具结果都只是 observation，不代表任务已经完成。
写入后必须读回验证。不要虚构工具结果，不要访问工作区以外的路径。
PROMPT);
    echo $answer . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, "错误：{$error->getMessage()}\n");
    exit(1);
}
