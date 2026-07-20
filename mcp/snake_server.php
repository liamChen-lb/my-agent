<?php

declare(strict_types=1);

$root = rtrim($argv[1] ?? getcwd(), DIRECTORY_SEPARATOR);

while (($line = fgets(STDIN)) !== false) {
    $request = json_decode($line, true);
    if (!is_array($request) || !isset($request['method'])) {
        continue;
    }

    $id = $request['id'] ?? null;
    if ($id === null) {
        continue;
    }

    try {
        $result = match ($request['method']) {
            'initialize' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => ['tools' => new stdClass()],
                'serverInfo' => ['name' => 'snake-demo-server', 'version' => '1.0.0'],
            ],
            'tools/list' => ['tools' => toolDefinitions()],
            'tools/call' => callTool(
                (string) ($request['params']['name'] ?? ''),
                is_array($request['params']['arguments'] ?? null) ? $request['params']['arguments'] : [],
                $root,
            ),
            default => throw new RuntimeException('不支持的方法：' . $request['method']),
        };
        respond(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    } catch (Throwable $error) {
        respond([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => -32603, 'message' => $error->getMessage()],
        ]);
    }
}

/** @return list<array<string, mixed>> */
function toolDefinitions(): array
{
    return [
        [
            'name' => 'snake_spec',
            'description' => '获取单文件贪吃蛇演示的验收标准。',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
        ],
        [
            'name' => 'validate_snake_html',
            'description' => '检查工作区内的 HTML 是否包含贪吃蛇演示所需的关键能力。',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => '相对工作区的 HTML 文件路径'],
                ],
                'required' => ['path'],
                'additionalProperties' => false,
            ],
        ],
    ];
}

/** @param array<string, mixed> $arguments @return array<string, mixed> */
function callTool(string $name, array $arguments, string $root): array
{
    $value = match ($name) {
        'snake_spec' => [
            'format' => '一个可直接打开、无外部依赖的 HTML 文件',
            'requirements' => [
                'Canvas 绘制棋盘、蛇和食物',
                '方向键与 WASD 控制，禁止直接反向',
                '显示分数、最高分和状态',
                '碰墙或撞到自己后结束',
                '空格键或按钮可开始、暂停和重新开始',
                '使用 requestAnimationFrame 驱动并按固定时间步更新',
                '适配高 DPI 与窄屏，HTML 内含 CSS 和 JavaScript',
            ],
        ],
        'validate_snake_html' => validateSnakeHtml($root, (string) ($arguments['path'] ?? '')),
        default => throw new InvalidArgumentException("未知 MCP 工具：{$name}"),
    };

    return [
        'content' => [[
            'type' => 'text',
            'text' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]],
        'isError' => false,
    ];
}

/** @return array<string, mixed> */
function validateSnakeHtml(string $root, string $relative): array
{
    if (
        $relative === ''
        || str_starts_with($relative, '/')
        || preg_match('/(^|[\\\\\/])\.\.([\\\\\/]|$)/', $relative) === 1
    ) {
        throw new InvalidArgumentException('path 必须是工作区内的相对路径');
    }
    $file = $root . DIRECTORY_SEPARATOR . ltrim($relative, './\\');
    $html = is_file($file) ? file_get_contents($file) : false;
    if ($html === false) {
        throw new RuntimeException('文件不存在或无法读取');
    }

    $checks = [
        'canvas' => preg_match('/<canvas\b/i', $html) === 1,
        'keyboard' => str_contains($html, 'keydown'),
        'animation_loop' => str_contains($html, 'requestAnimationFrame'),
        'score' => preg_match('/score|分数/i', $html) === 1,
        'collision' => preg_match('/collision|gameOver|撞|碰墙/i', $html) === 1,
        'restart' => preg_match('/restart|reset|重新/i', $html) === 1,
        'single_file' => preg_match('/<script[^>]+src=|<link[^>]+stylesheet/i', $html) !== 1,
    ];

    return [
        'valid' => !in_array(false, $checks, true),
        'checks' => $checks,
        'bytes' => strlen($html),
    ];
}

/** @param array<string, mixed> $message */
function respond(array $message): void
{
    echo json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    flush();
}
