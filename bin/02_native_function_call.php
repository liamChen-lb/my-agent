<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use DemoAgent\Cli\DemoOptions;
use DemoAgent\Llm\LlmFactory;
use DemoAgent\Tools\CallableTool;
use DemoAgent\Tools\ToolRegistry;

try {
    $options = DemoOptions::fromArgv($argv, '计算 (2026 - 2022) * 12。必须使用工具，不要心算。');
    $llm = LlmFactory::forProfile($options->profile(), '02-native-function-call');
    $tools = new ToolRegistry($llm->logger());
    $tools->register(new CallableTool(
        'calculate',
        '计算只包含数字、小数点、空格、括号和四则运算符的表达式。',
        [
            'type' => 'object',
            'properties' => ['expression' => ['type' => 'string']],
            'required' => ['expression'],
            'additionalProperties' => false,
        ],
        static function (array $arguments): array {
            $expression = (string) ($arguments['expression'] ?? '');
            if ($expression === '' || preg_match('/^[\d\s.+\-*\/()]+$/', $expression) !== 1) {
                throw new InvalidArgumentException('表达式包含不允许的字符');
            }
            /** @var int|float $value */
            $value = eval("return {$expression};");

            return ['expression' => $expression, 'value' => $value];
        },
    ));

    $assistant = $llm->complete([
        ['role' => 'system', 'content' => '按需调用工具。'],
        ['role' => 'user', 'content' => $options->task()],
    ], $tools->schemas(), [], 'native-function-call');

    $calls = is_array($assistant['tool_calls'] ?? null) ? $assistant['tool_calls'] : [];
    if ($calls === []) {
        echo ($assistant['content'] ?? '[没有工具调用]') . PHP_EOL;
        exit(0);
    }

    foreach ($calls as $call) {
        $function = is_array($call['function'] ?? null) ? $call['function'] : [];
        $arguments = json_decode((string) ($function['arguments'] ?? '{}'), true);
        echo $tools->execute(
            (string) ($function['name'] ?? ''),
            is_array($arguments) ? $arguments : [],
        ) . PHP_EOL;
    }
    echo "注意：本版本只执行一次工具，没有把 Observation 送回模型，因此还不是完整 Agent loop。\n";
} catch (Throwable $error) {
    fwrite(STDERR, "错误：{$error->getMessage()}\n");
    exit(1);
}
