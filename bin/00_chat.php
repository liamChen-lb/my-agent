<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use DemoAgent\Cli\DemoOptions;
use DemoAgent\Llm\LlmFactory;

try {
    $options = DemoOptions::fromArgv($argv, '用一句话解释：为什么 LLM API 自身是无状态的？');
    $llm = LlmFactory::forProfile($options->profile(), '00-chat');
    $message = $llm->complete([
        ['role' => 'system', 'content' => '你是技术分享助手。回答准确、简洁；不确定时明确说明。'],
        ['role' => 'user', 'content' => $options->task()],
    ], [], [], 'single.chat');

    echo ($message['content'] ?? '[模型没有返回文本]') . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, "错误：{$error->getMessage()}\n");
    exit(1);
}
