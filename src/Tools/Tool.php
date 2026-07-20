<?php

declare(strict_types=1);

namespace DemoAgent\Tools;

interface Tool
{
    public function name(): string;

    public function description(): string;

    /** @return array<string, mixed> */
    public function parameters(): array;

    /** @param array<string, mixed> $arguments */
    public function invoke(array $arguments): mixed;
}
