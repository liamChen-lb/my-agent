<?php

declare(strict_types=1);

namespace DemoAgent\Observability;

final class TranscriptLogger
{
    public function __construct(
        private readonly string $file,
        private readonly bool $printToConsole = true,
    ) {
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException("无法创建日志目录：{$directory}");
        }
    }

    /**
     * 日志刻意保留完整的 LLM 请求和响应，方便分享时用 tail -f 观察。
     *
     * @param array<string, mixed> $data
     */
    public function record(string $type, array $data): void
    {
        $event = [
            'time' => date(DATE_ATOM),
            'type' => $type,
            'data' => $data,
        ];
        $line = json_encode(
            $event,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_THROW_ON_ERROR,
        );

        file_put_contents($this->file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

        if ($this->printToConsole) {
            fwrite(STDERR, "\n--- {$type} ---\n");
            fwrite(
                STDERR,
                json_encode(
                    $data,
                    JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_INVALID_UTF8_SUBSTITUTE
                    | JSON_THROW_ON_ERROR,
                ) . PHP_EOL,
            );
        }
    }

    public function path(): string
    {
        return $this->file;
    }
}
