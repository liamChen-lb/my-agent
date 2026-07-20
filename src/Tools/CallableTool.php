<?php

declare(strict_types=1);

namespace DemoAgent\Tools;

final class CallableTool implements Tool
{
    /** @var \Closure(array<string, mixed>): mixed */
    private readonly \Closure $handler;

    /**
     * @param array<string, mixed> $parameters
     * @param callable(array<string, mixed>): mixed $handler
     */
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly array $parameters,
        callable $handler,
    ) {
        $this->handler = \Closure::fromCallable($handler);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function parameters(): array
    {
        return $this->parameters;
    }

    public function invoke(array $arguments): mixed
    {
        return ($this->handler)($arguments);
    }
}
