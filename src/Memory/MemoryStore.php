<?php

declare(strict_types=1);

namespace DemoAgent\Memory;

final class MemoryStore
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException("无法创建记忆目录：{$directory}");
        }
    }

    /** @param list<string> $tags */
    public function remember(string $content, array $tags = []): array
    {
        $entry = [
            'time' => date(DATE_ATOM),
            'content' => trim($content),
            'tags' => array_values(array_filter($tags, 'is_string')),
        ];
        if ($entry['content'] === '') {
            throw new \InvalidArgumentException('记忆内容不能为空');
        }

        $file = $this->todayFile();
        file_put_contents(
            $file,
            json_encode(
                $entry,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );

        return ['ok' => true, 'file' => basename($file), 'entry' => $entry];
    }

    /** @return list<array<string, mixed>> */
    public function search(string $query, int $limit = 5): array
    {
        $entries = $this->allEntries();
        $terms = array_values(array_filter(
            preg_split('/[\s,，。；;：:、]+/u', mb_strtolower($query)) ?: [],
            static fn (string $term): bool => mb_strlen($term) >= 2,
        ));

        foreach ($entries as &$entry) {
            $haystack = mb_strtolower(
                (string) ($entry['content'] ?? '') . ' ' . implode(' ', (array) ($entry['tags'] ?? [])),
            );
            $entry['_score'] = array_sum(array_map(
                static fn (string $term): int => str_contains($haystack, $term) ? 1 : 0,
                $terms,
            ));
        }
        unset($entry);

        usort($entries, static function (array $left, array $right): int {
            return [$right['_score'], $right['time']] <=> [$left['_score'], $left['time']];
        });

        return array_map(static function (array $entry): array {
            unset($entry['_score']);

            return $entry;
        }, array_slice($entries, 0, max(1, min($limit, 20))));
    }

    /** @return list<array<string, mixed>> */
    private function allEntries(): array
    {
        $entries = [];
        foreach (glob($this->directory . '/*.log') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $entry = json_decode($line, true);
                if (is_array($entry)) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    private function todayFile(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . date('Y-m-d') . '.log';
    }
}
