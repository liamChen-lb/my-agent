<?php

declare(strict_types=1);

namespace DemoAgent\Cli;

/**
 * bin/00-06 教学脚本共用的命令行参数。
 */
final readonly class DemoOptions
{
    private function __construct(
        private string $profile,
        private string $task,
    ) {
    }

    /**
     * @param list<string> $argv
     * @param list<string> $allowedProfiles
     */
    public static function fromArgv(
        array $argv,
        string $defaultTask,
        array $allowedProfiles = ['default', 'cloud', 'local'],
        string $defaultProfile = 'default',
    ): self {
        if (!in_array($defaultProfile, $allowedProfiles, true)) {
            throw new \InvalidArgumentException("默认 profile 不受支持：{$defaultProfile}");
        }

        $profile = $defaultProfile;
        $taskParts = [];
        $count = count($argv);

        for ($index = 1; $index < $count; $index++) {
            $argument = $argv[$index];

            if ($argument === '--profile') {
                $profile = $argv[++$index] ?? throw new \InvalidArgumentException('--profile 缺少值');
                continue;
            }
            if (str_starts_with($argument, '--profile=')) {
                $profile = substr($argument, strlen('--profile='));
                if ($profile === '') {
                    throw new \InvalidArgumentException('--profile 缺少值');
                }
                continue;
            }
            if (str_starts_with($argument, '--')) {
                throw new \InvalidArgumentException("未知选项：{$argument}");
            }

            $taskParts[] = $argument;
        }

        $profile = strtolower($profile);
        if (!in_array($profile, $allowedProfiles, true)) {
            throw new \InvalidArgumentException(
                'profile 只能是：' . implode('、', $allowedProfiles),
            );
        }

        return new self(
            $profile,
            $taskParts === [] ? $defaultTask : implode(' ', $taskParts),
        );
    }

    public function profile(): string
    {
        return $this->profile;
    }

    public function task(): string
    {
        return $this->task;
    }
}
