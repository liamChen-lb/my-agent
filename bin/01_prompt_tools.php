<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use DemoAgent\Cli\DemoOptions;
use DemoAgent\Llm\LlmFactory;
use DemoAgent\Tools\ToolRegistry;
use DemoAgent\Tools\WorkspaceTools;

$workspace = project_path('var/workspaces/prompt-tools');

try {
    $options = DemoOptions::fromArgv($argv, '查看工作区，然后创建 hello.txt，内容为“Agent 的动作由程序执行”。');
    $task = $options->task();
    $llm = LlmFactory::forProfile($options->profile(), '01-prompt-tools');
    $tools = new ToolRegistry($llm->logger());
    WorkspaceTools::register($tools, $workspace);
    $toolDescription = json_encode($tools->schemas(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    $messages = [
        [
            'role' => 'system',
            'content' => <<<PROMPT
你是一个早期工具 Agent。可用工具如下：
{$toolDescription}

需要调用工具时，只输出：
<tool>{"name":"工具名","arguments":{"参数":"值"}}</tool>
一次只调用一个工具。程序会执行它并返回 observation。
任务完成时直接给最终答案，不要输出 <tool>。
PROMPT,
        ],
        ['role' => 'user', 'content' => $task],
    ];

    for ($step = 1; $step <= 8; $step++) {
        $assistant = $llm->complete($messages, [], [], "prompt-tool.step.{$step}");
        $content = (string) ($assistant['content'] ?? '');
        $messages[] = $assistant;

        if (preg_match('/<tool>\s*(\{.*?\})\s*<\/tool>/s', $content, $match) !== 1) {
            echo $content . PHP_EOL;
            exit(0);
        }

        $call = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        $observation = $tools->execute(
            (string) ($call['name'] ?? ''),
            is_array($call['arguments'] ?? null) ? $call['arguments'] : [],
        );
        $messages[] = ['role' => 'user', 'content' => "<observation>{$observation}</observation>"];
    }

    throw new RuntimeException('达到最大循环次数');
} catch (Throwable $error) {
    fwrite(STDERR, "错误：{$error->getMessage()}\n");
    exit(1);
}
