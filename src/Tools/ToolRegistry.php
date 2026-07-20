<?php

declare(strict_types=1);

namespace DemoAgent\Tools;

use DemoAgent\Observability\TranscriptLogger;

final class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    public function __construct(private readonly TranscriptLogger $logger)
    {
    }

    public function register(Tool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /** @return list<array<string, mixed>> */
    public function schemas(): array
    {
        return array_values(array_map(
            static fn (Tool $tool): array => [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->parameters(),
                ],
            ],
            $this->tools,
        ));
    }

    /** @param array<string, mixed> $arguments */
    public function execute(string $name, array $arguments): string
    {
        $this->logger->record('tool.request', ['name' => $name, 'arguments' => $arguments]);

        try {
            $tool = $this->tools[$name] ?? throw new \InvalidArgumentException("未知工具：{$name}");
            $result = $tool->invoke($arguments);
            $content = is_string($result)
                ? $result
                : json_encode(
                    $result,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_INVALID_UTF8_SUBSTITUTE
                    | JSON_THROW_ON_ERROR,
                );
            $this->logger->record('tool.response', ['name' => $name, 'ok' => true, 'content' => $content]);

            return $content;
        } catch (\Throwable $error) {
            $content = json_encode([
                'ok' => false,
                'error' => $error->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            $this->logger->record('tool.response', ['name' => $name, 'ok' => false, 'content' => $content]);

            return $content;
        }
    }
}
