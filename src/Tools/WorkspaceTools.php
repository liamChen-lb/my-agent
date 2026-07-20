<?php

declare(strict_types=1);

namespace DemoAgent\Tools;

final class WorkspaceTools
{
    public static function register(ToolRegistry $registry, string $root): void
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        $registry->register(new CallableTool(
            'list_files',
            '列出工作区中的文件。先查看目录再决定读取哪些文件。',
            [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => '相对工作区路径，默认当前目录'],
                ],
                'additionalProperties' => false,
            ],
            static function (array $arguments) use ($root): array {
                $directory = self::safePath($root, (string) ($arguments['path'] ?? '.'));
                if (!is_dir($directory)) {
                    throw new \RuntimeException('目录不存在');
                }

                $items = [];
                foreach (new \DirectoryIterator($directory) as $item) {
                    if ($item->isDot()) {
                        continue;
                    }
                    $items[] = [
                        'name' => $item->getFilename(),
                        'type' => $item->isDir() ? 'directory' : 'file',
                    ];
                }

                return $items;
            },
        ));

        $registry->register(new CallableTool(
            'read_file',
            '读取工作区内的 UTF-8 文本文件。',
            [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => '相对工作区文件路径'],
                ],
                'required' => ['path'],
                'additionalProperties' => false,
            ],
            static function (array $arguments) use ($root): string {
                $file = self::safePath($root, self::requiredString($arguments, 'path'));
                if (!is_file($file)) {
                    throw new \RuntimeException('文件不存在');
                }

                $content = file_get_contents($file);
                if ($content === false) {
                    throw new \RuntimeException('读取文件失败');
                }

                return $content;
            },
        ));

        $registry->register(new CallableTool(
            'write_file',
            '创建或覆盖工作区内的文本文件。只能写入演示沙箱。',
            [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => '相对工作区文件路径'],
                    'content' => ['type' => 'string', 'description' => '完整文件内容'],
                ],
                'required' => ['path', 'content'],
                'additionalProperties' => false,
            ],
            static function (array $arguments) use ($root): array {
                $file = self::safePath($root, self::requiredString($arguments, 'path'));
                $directory = dirname($file);
                if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                    throw new \RuntimeException('创建目录失败');
                }
                $content = self::requiredString($arguments, 'content');
                if (file_put_contents($file, $content, LOCK_EX) === false) {
                    throw new \RuntimeException('写入文件失败');
                }

                return ['ok' => true, 'path' => substr($file, strlen($root) + 1), 'bytes' => strlen($content)];
            },
        ));
    }

    public static function safePath(string $root, string $relative): string
    {
        if ($relative === '' || str_contains($relative, "\0")) {
            throw new \InvalidArgumentException('路径不能为空');
        }
        if (str_starts_with($relative, '/') || preg_match('/(^|[\\\\\/])\.\.([\\\\\/]|$)/', $relative) === 1) {
            throw new \InvalidArgumentException('路径必须位于工作区内');
        }

        return $root . DIRECTORY_SEPARATOR . ltrim($relative, './\\');
    }

    /** @param array<string, mixed> $arguments */
    private static function requiredString(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException("参数 {$key} 必须是非空字符串");
        }

        return $value;
    }
}
