<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use DemoAgent\Agent\AgentLoop;
use DemoAgent\Cli\DemoOptions;
use DemoAgent\Context\ContextManager;
use DemoAgent\Llm\LlmFactory;
use DemoAgent\Mcp\McpClient;
use DemoAgent\Memory\MemoryStore;
use DemoAgent\Memory\MemoryTools;
use DemoAgent\Skills\SkillCatalog;
use DemoAgent\Tools\ToolRegistry;
use DemoAgent\Tools\WorkspaceTools;

$workspace = project_path('var/workspaces/modern');

try {
    $options = DemoOptions::fromArgv($argv, '创建 snake.html：一个无需构建、可直接在浏览器打开的贪吃蛇游戏。完成后验证它。');
    $task = $options->task();
    if (!is_dir($workspace) && !mkdir($workspace, 0777, true) && !is_dir($workspace)) {
        throw new RuntimeException("无法创建工作区：{$workspace}");
    }

    $llm = LlmFactory::forProfile($options->profile(), '05-modern-agent');
    $tools = new ToolRegistry($llm->logger());
    WorkspaceTools::register($tools, $workspace);

    $memory = new MemoryStore(project_path('var/memory'));
    MemoryTools::register($tools, $memory);

    $skills = new SkillCatalog(project_path('skills'));
    $skills->registerTool($tools);

    $mcp = new McpClient(
        [PHP_BINARY, project_path('mcp/snake_server.php'), $workspace],
        $llm->logger(),
        project_path(),
    );
    $mcp->registerTools($tools);

    $context = new ContextManager(
        $llm,
        tokenBudget: (int) (env('CONTEXT_TOKEN_BUDGET', '12000') ?? '12000'),
        maxOldToolChars: (int) (env('MAX_OLD_TOOL_CHARS', '1200') ?? '1200'),
    );
    $agent = new AgentLoop($llm, $tools, $context, maxSteps: 16);

    $recalled = $memory->search($task, 3);
    $systemPrompt = <<<'PROMPT'
你是现代教学型 Coding Agent。LLM 只提出文本形式的工具调用；真正的 I/O 和协议调用由 Agent runtime 执行。

工作方式：
1. 先理解目标并获取必要上下文，再行动；不虚构文件内容或工具结果。
2. Skill 采用渐进式披露：任务匹配某个 Skill 时，先调用 load_skill，而不是猜测完整流程。
3. `mcp_` 前缀的工具来自外部 MCP Server；MCP 只标准化连接，不会自动保证工具安全。
4. memory 位于上下文窗口之外。只把跨任务仍有价值的信息写入长期记忆。
5. 写入文件后调用适当工具验证；失败则根据 observation 修复。
6. 尽量保持静态指令在前、动态结果在后，以利前缀缓存。
PROMPT;
    $systemPrompt .= "\n\n" . $skills->metadataPrompt();
    if ($recalled !== []) {
        $systemPrompt .= "\n\n按当前任务检索到的外部记忆（可能不相关，需自行判断）：\n"
            . json_encode($recalled, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    $answer = $agent->run($task, $systemPrompt);
    echo $answer . PHP_EOL;
    echo "\nLLM 累计指标："
        . json_encode($llm->metrics(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        . PHP_EOL;
    if (is_file($workspace . '/snake.html')) {
        echo "\n可直接打开：" . $workspace . "/snake.html\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, "错误：{$error->getMessage()}\n");
    exit(1);
}
