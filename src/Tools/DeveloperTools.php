<?php

declare(strict_types=1);

namespace DemoAgent\Tools;

/**
 * 项目级 Coding Agent 工具。Shell 工具必须由调用方提供审批策略。
 */
final class DeveloperTools
{
    /**
     * @param callable(string): bool $approveCommand
     */
    public static function register(
        ToolRegistry $registry,
        string $root,
        callable $approveCommand,
        bool $shellEnabled = true,
        bool $editEnabled = true,
    ): void {
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        $registry->register(new CallableTool(
            'search_files',
            '在工作区文本文件中搜索字面量，返回文件、行号和匹配行。优先搜索再读取完整文件。',
            [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => '要搜索的非空字面量'],
                    'path' => ['type' => 'string', 'description' => '相对工作区目录或文件，默认当前目录'],
                    'max_results' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ],
            static function (array $arguments) use ($root): array {
                $query = self::requiredString($arguments, 'query');
                $path = WorkspaceTools::safePath($root, (string) ($arguments['path'] ?? '.'));
                $limit = max(1, min(200, (int) ($arguments['max_results'] ?? 50)));
                $files = is_file($path) ? [$path] : self::textFiles($path);
                $results = [];

                foreach ($files as $file) {
                    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
                        if (mb_stripos($line, $query) === false) {
                            continue;
                        }
                        $results[] = [
                            'path' => ltrim(substr($file, strlen($root)), DIRECTORY_SEPARATOR),
                            'line' => $index + 1,
                            'text' => mb_strlen($line) > 300 ? mb_substr($line, 0, 300) . '…' : $line,
                        ];
                        if (count($results) >= $limit) {
                            return ['matches' => $results, 'truncated' => true];
                        }
                    }
                }

                return ['matches' => $results, 'truncated' => false];
            },
        ));

        if ($editEnabled) {
            $registry->register(new CallableTool(
                'edit_file',
                '对工作区文本文件执行一次精确字符串替换。old_string 必须恰好出现一次，避免误改。',
                [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => '相对工作区文件路径'],
                        'old_string' => ['type' => 'string', 'description' => '要替换的完整原文'],
                        'new_string' => ['type' => 'string', 'description' => '替换后的完整文本，可为空'],
                    ],
                    'required' => ['path', 'old_string', 'new_string'],
                    'additionalProperties' => false,
                ],
                static function (array $arguments) use ($root): array {
                    $file = WorkspaceTools::safePath($root, self::requiredString($arguments, 'path'));
                    if (!is_file($file)) {
                        throw new \RuntimeException('文件不存在');
                    }
                    $content = file_get_contents($file);
                    if ($content === false) {
                        throw new \RuntimeException('读取文件失败');
                    }

                    $old = self::requiredString($arguments, 'old_string');
                    $new = $arguments['new_string'] ?? null;
                    if (!is_string($new)) {
                        throw new \InvalidArgumentException('参数 new_string 必须是字符串');
                    }
                    $occurrences = substr_count($content, $old);
                    if ($occurrences !== 1) {
                        throw new \RuntimeException("old_string 应恰好出现一次，实际出现 {$occurrences} 次");
                    }

                    $updated = str_replace($old, $new, $content);
                    if (file_put_contents($file, $updated, LOCK_EX) === false) {
                        throw new \RuntimeException('写入文件失败');
                    }

                    return [
                        'ok' => true,
                        'path' => ltrim(substr($file, strlen($root)), DIRECTORY_SEPARATOR),
                        'bytes' => strlen($updated),
                    ];
                },
            ));
        }

        if (!$shellEnabled) {
            return;
        }

        $registry->register(new CallableTool(
            'run_command',
            '在工作区执行 Shell 命令，用于运行测试、格式化、构建和 Git 只读检查。执行前由 CLI 请求用户批准。',
            [
                'type' => 'object',
                'properties' => [
                    'command' => ['type' => 'string', 'description' => '要执行的 Shell 命令'],
                    'timeout_seconds' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 600],
                ],
                'required' => ['command'],
                'additionalProperties' => false,
            ],
            static function (array $arguments) use ($root, $approveCommand): array {
                $command = self::requiredString($arguments, 'command');
                if (!$approveCommand($command)) {
                    throw new \RuntimeException('用户拒绝执行命令');
                }

                return self::runCommand(
                    $command,
                    $root,
                    max(1, min(600, (int) ($arguments['timeout_seconds'] ?? 120))),
                );
            },
        ));
    }

    /** @return list<string> */
    private static function textFiles(string $path): array
    {
        if (!is_dir($path)) {
            throw new \RuntimeException('搜索路径不存在');
        }

        $files = [];
        $directory = new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            static function (\SplFileInfo $item): bool {
                if ($item->isDir()) {
                    return !in_array($item->getFilename(), [
                        '.git', 'vendor', 'node_modules', 'var', 'dist', 'build',
                    ], true);
                }

                return $item->isFile() && $item->getSize() <= 1_000_000;
            },
        );

        foreach (new \RecursiveIteratorIterator($filter) as $item) {
            if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                continue;
            }
            $sample = file_get_contents($item->getPathname(), false, null, 0, 4096);
            if ($sample !== false && !str_contains($sample, "\0")) {
                $files[] = $item->getPathname();
            }
        }

        return $files;
    }

    /** @return array<string, mixed> */
    private static function runCommand(string $command, string $root, int $timeoutSeconds): array
    {
        $process = proc_open(
            ['/bin/zsh', '-lc', $command],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('无法启动命令');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $startedAt = microtime(true);
        $timedOut = false;
        $exitCode = -1;

        while (true) {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) - $startedAt >= $timeoutSeconds) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(20_000);
        }

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return [
            'command' => $command,
            'exit_code' => $timedOut ? null : $exitCode,
            'timed_out' => $timedOut,
            'stdout' => self::truncate($stdout),
            'stderr' => self::truncate($stderr),
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
        ];
    }

    private static function truncate(string $output, int $limit = 12_000): string
    {
        if (strlen($output) <= $limit) {
            return $output;
        }

        return substr($output, 0, $limit) . "\n[输出已截断]";
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
