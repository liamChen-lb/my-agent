<?php

declare(strict_types=1);

namespace DemoAgent\Skills;

use DemoAgent\Tools\CallableTool;
use DemoAgent\Tools\ToolRegistry;

final class SkillCatalog
{
    /** @var array<string, array{name: string, description: string, file: string}> */
    private array $skills = [];

    public function __construct(private readonly string $directory)
    {
        foreach (glob(rtrim($directory, '/') . '/*/SKILL.md') ?: [] as $file) {
            $metadata = $this->parseMetadata($file);
            $this->skills[$metadata['name']] = [...$metadata, 'file' => $file];
        }
    }

    public function metadataPrompt(): string
    {
        if ($this->skills === []) {
            return '当前没有安装 Skills。';
        }

        $lines = ['可用 Skills（这里只加载元数据；需要时调用 load_skill 获取完整指令）：'];
        foreach ($this->skills as $skill) {
            $lines[] = "- {$skill['name']}: {$skill['description']}";
        }

        return implode(PHP_EOL, $lines);
    }

    public function registerTool(ToolRegistry $registry): void
    {
        $names = array_keys($this->skills);
        $registry->register(new CallableTool(
            'load_skill',
            '按名称加载一个 Skill 的完整操作说明。只在任务匹配时调用，实现渐进式披露。',
            [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'enum' => $names],
                ],
                'required' => ['name'],
                'additionalProperties' => false,
            ],
            function (array $arguments): string {
                $name = (string) ($arguments['name'] ?? '');
                $skill = $this->skills[$name] ?? throw new \InvalidArgumentException("Skill 不存在：{$name}");
                $content = file_get_contents($skill['file']);
                if ($content === false) {
                    throw new \RuntimeException('读取 Skill 失败');
                }

                return $content;
            },
        ));
    }

    /** @return array{name: string, description: string} */
    private function parseMetadata(string $file): array
    {
        $content = file_get_contents($file);
        if ($content === false || preg_match('/\A---\R(.*?)\R---\R/s', $content, $match) !== 1) {
            throw new \RuntimeException("Skill 缺少 YAML frontmatter：{$file}");
        }

        $metadata = [];
        foreach (preg_split('/\R/', $match[1]) ?: [] as $line) {
            if (preg_match('/^([a-zA-Z_-]+):\s*(.+)$/', $line, $parts) === 1) {
                $metadata[$parts[1]] = trim($parts[2], " \t\"'");
            }
        }

        if (($metadata['name'] ?? '') === '' || ($metadata['description'] ?? '') === '') {
            throw new \RuntimeException("Skill 必须提供 name 和 description：{$file}");
        }

        return ['name' => $metadata['name'], 'description' => $metadata['description']];
    }
}
