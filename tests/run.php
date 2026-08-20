<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use DemoAgent\Agent\SubAgentManager;
use DemoAgent\Cli\DemoOptions;
use DemoAgent\Cli\TerminalInput;
use DemoAgent\Context\ContextManager;
use DemoAgent\Llm\LlmClient;
use DemoAgent\Mcp\HttpMcpClient;
use DemoAgent\Mcp\McpClient;
use DemoAgent\Memory\MemoryStore;
use DemoAgent\Observability\TranscriptLogger;
use DemoAgent\Skills\SkillCatalog;
use DemoAgent\Tools\DeveloperTools;
use DemoAgent\Tools\ToolRegistry;
use DemoAgent\Tools\WorkspaceTools;

$failures = 0;
$test = static function (string $name, callable $callback) use (&$failures): void {
    try {
        $callback();
        echo "✓ {$name}\n";
    } catch (Throwable $error) {
        $failures++;
        echo "✗ {$name}: {$error->getMessage()}\n";
    }
};
$assert = static function (bool $condition, string $message = '断言失败'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$runtime = project_path('var/tests/' . bin2hex(random_bytes(4)));
mkdir($runtime, 0777, true);
$logger = new TranscriptLogger($runtime . '/test.jsonl', false);

$test('Composer PSR-4 映射可定位 AgentSession 源码', static function () use ($assert): void {
    $composer = json_decode(
        (string) file_get_contents(project_path('composer.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $assert(($composer['autoload']['psr-4']['DemoAgent\\'] ?? null) === 'src/');

    $class = new ReflectionClass(DemoAgent\Agent\AgentSession::class);
    $assert(realpath((string) $class->getFileName()) === realpath(project_path('src/Agent/AgentSession.php')));
});

$test('演示脚本统一解析 LLM profile 和任务', static function () use ($assert): void {
    $cloud = DemoOptions::fromArgv(
        ['bin/00_chat.php', '--profile=cloud', '解释', 'Agent'],
        '默认任务',
    );
    $assert($cloud->profile() === 'cloud');
    $assert($cloud->task() === '解释 Agent');

    $local = DemoOptions::fromArgv(
        ['bin/03_react_agent.php', '--profile', 'local'],
        '默认任务',
    );
    $assert($local->profile() === 'local');
    $assert($local->task() === '默认任务');

    $all = DemoOptions::fromArgv(
        ['bin/06_compare_models.php', '--profile=all'],
        '',
        ['all', 'cloud', 'local'],
        'all',
    );
    $assert($all->profile() === 'all');
});

$test('日志时间使用东八区 ISO 8601 格式', static function () use ($runtime, $assert): void {
    $file = $runtime . '/timezone.jsonl';
    (new TranscriptLogger($file, false))->record('timezone.test', []);
    $event = json_decode((string) file_get_contents($file), true);
    $assert(
        is_array($event)
        && preg_match('/\+08:00$/', (string) ($event['time'] ?? '')) === 1,
        '日志时间必须带 +08:00 偏移',
    );
});

$test('日志遇到非法 UTF-8 时替换字符而不中断 Agent', static function () use ($runtime, $assert): void {
    $file = $runtime . '/invalid-utf8.jsonl';
    (new TranscriptLogger($file, false))->record('utf8.test', [
        'content' => "before\xB1after",
    ]);
    $event = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $assert(($event['data']['content'] ?? '') === "before\u{FFFD}after");
});

$test('终端退格只删除用户输入字符', static function () use ($assert): void {
    $assert(TerminalInput::removeLastCharacter('') === '');
    $assert(TerminalInput::removeLastCharacter('abc') === 'ab');
    $assert(TerminalInput::removeLastCharacter('中文') === '中');
    $assert(TerminalInput::removeLastCharacter('a🐍') === 'a');
    $readlinePrompt = TerminalInput::readlinePrompt("\033[1;34m你\033[0m[1] > ");
    $assert(str_contains($readlinePrompt, "\001\033[1;34m\002"));
    $assert(str_contains($readlinePrompt, "\001\033[0m\002"));
});

$test('工作区工具可写入并读取文件', static function () use ($runtime, $logger, $assert): void {
    $registry = new ToolRegistry($logger);
    WorkspaceTools::register($registry, $runtime . '/workspace');
    $written = json_decode($registry->execute('write_file', ['path' => 'a.txt', 'content' => 'hello']), true);
    $assert(($written['ok'] ?? false) === true);
    $assert($registry->execute('read_file', ['path' => 'a.txt']) === 'hello');
});

$test('工作区工具拒绝目录穿越', static function () use ($runtime, $logger, $assert): void {
    $registry = new ToolRegistry($logger);
    WorkspaceTools::register($registry, $runtime . '/workspace');
    $result = json_decode($registry->execute('read_file', ['path' => '../secret']), true);
    $assert(($result['ok'] ?? true) === false);
});

$test('项目级工具支持搜索、精确编辑和命令执行', static function () use ($runtime, $logger, $assert): void {
    $workspace = $runtime . '/developer-workspace';
    mkdir($workspace, 0777, true);
    file_put_contents($workspace . '/demo.php', "<?php\n// old-value\n");

    $registry = new ToolRegistry($logger);
    DeveloperTools::register($registry, $workspace, static fn (string $command): bool => true);

    $search = json_decode($registry->execute('search_files', ['query' => 'old-value']), true);
    $assert(($search['matches'][0]['path'] ?? '') === 'demo.php');

    $edited = json_decode($registry->execute('edit_file', [
        'path' => 'demo.php',
        'old_string' => 'old-value',
        'new_string' => 'new-value',
    ]), true);
    $assert(($edited['ok'] ?? false) === true);
    $assert(str_contains((string) file_get_contents($workspace . '/demo.php'), 'new-value'));

    $command = json_decode($registry->execute('run_command', [
        'command' => "php -r 'echo 6 * 7;'",
    ]), true);
    $assert(($command['exit_code'] ?? -1) === 0);
    $assert(($command['stdout'] ?? '') === '42');
});

$test('只读工具集不会向 Sub-agent 暴露写入、编辑或 Shell', static function () use ($runtime, $logger, $assert): void {
    $registry = new ToolRegistry($logger);
    WorkspaceTools::register($registry, $runtime, writeEnabled: false);
    DeveloperTools::register(
        $registry,
        $runtime,
        static fn (string $_command): bool => false,
        shellEnabled: false,
        editEnabled: false,
    );
    $names = array_map(
        static fn (array $schema): string => (string) ($schema['function']['name'] ?? ''),
        $registry->schemas(),
    );
    $assert(in_array('list_files', $names, true));
    $assert(in_array('read_file', $names, true));
    $assert(in_array('search_files', $names, true));
    $assert(!in_array('write_file', $names, true));
    $assert(!in_array('edit_file', $names, true));
    $assert(!in_array('run_command', $names, true));
});

$test('Sub-agent 注册隔离委派工具和两种权限模式', static function () use ($runtime, $logger, $assert): void {
    $llm = new LlmClient('http://127.0.0.1', 'unused', 'mock', $logger);
    $registry = new ToolRegistry($logger);
    $subAgents = new SubAgentManager($llm, $runtime, maxSteps: 2, maxInvocations: 1);
    $subAgents->registerTool($registry);
    $schema = $registry->schemas()[0]['function'] ?? [];
    $assert(($schema['name'] ?? null) === 'delegate_task');
    $assert(($schema['parameters']['properties']['mode']['enum'] ?? null) === ['research', 'workspace']);
    $assert(($schema['parameters']['additionalProperties'] ?? null) === false);
});

$test('HTTP MCP Client 拒绝非 HTTP transport', static function () use ($logger, $assert): void {
    try {
        new HttpMcpClient('file:///tmp/mcp.sock', [], $logger);
        $assert(false, '应拒绝 file transport');
    } catch (InvalidArgumentException $error) {
        $assert(str_contains($error->getMessage(), 'http'));
    }
});

$test('Skill 只预加载元数据并可按需加载正文', static function () use ($logger, $assert): void {
    $catalog = new SkillCatalog(project_path('skills'));
    $assert(str_contains($catalog->metadataPrompt(), 'snake-game'));
    $registry = new ToolRegistry($logger);
    $catalog->registerTool($registry);
    $body = $registry->execute('load_skill', ['name' => 'snake-game']);
    $assert(str_contains($body, 'requestAnimationFrame'));
});

$test('外部 Memory 可跨实例检索', static function () use ($runtime, $assert): void {
    $directory = $runtime . '/memory';
    (new MemoryStore($directory))->remember('项目统一使用 PHP 8.2', ['php']);
    $results = (new MemoryStore($directory))->search('PHP');
    $assert(str_contains((string) ($results[0]['content'] ?? ''), '8.2'));
});

$test('上下文管理会清理较旧的大工具输出', static function () use ($runtime, $logger, $assert): void {
    $llm = new LlmClient('http://127.0.0.1', 'unused', 'mock', $logger);
    $manager = new ContextManager($llm, tokenBudget: 100000, maxOldToolChars: 10, keepRecentMessages: 2);
    $messages = [
        ['role' => 'system', 'content' => 's'],
        ['role' => 'user', 'content' => 'u'],
        ['role' => 'assistant', 'content' => null, 'tool_calls' => []],
        ['role' => 'tool', 'content' => str_repeat('x', 100)],
        ['role' => 'assistant', 'content' => 'done'],
        ['role' => 'user', 'content' => 'next'],
    ];
    $prepared = $manager->prepare($messages);
    $assert(str_contains((string) $prepared[3]['content'], '已清理'));
});

$test('MCP Server 可发现工具并验证示例 HTML', static function () use ($logger, $assert): void {
    $client = new McpClient(
        [PHP_BINARY, project_path('mcp/snake_server.php'), project_path()],
        $logger,
        project_path(),
    );
    $registry = new ToolRegistry($logger);
    $client->registerTools($registry);
    $schemas = json_encode($registry->schemas(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $assert(
        str_contains($schemas, '"name":"mcp_snake_spec"')
        && str_contains($schemas, '"properties":{}'),
        'MCP 空对象 Schema 应编码为 {} 而不是 []',
    );
    $result = json_decode($registry->execute('mcp_validate_snake_html', ['path' => 'examples/snake.html']), true);
    $text = $result['content'][0]['text'] ?? '{}';
    $validation = json_decode((string) $text, true);
    $assert(($validation['valid'] ?? false) === true, '贪吃蛇 HTML 未通过 MCP 验证');
});

echo $failures === 0 ? "\n全部测试通过。\n" : "\n{$failures} 个测试失败。\n";
exit($failures === 0 ? 0 : 1);
