<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use DemoAgent\Agent\AgentLoop;
use DemoAgent\Cli\DemoOptions;
use DemoAgent\Llm\LlmFactory;
use DemoAgent\Tools\ToolRegistry;
use DemoAgent\Tools\WorkspaceTools;

try {
    $options = DemoOptions::fromArgv($argv, '', ['all', 'cloud', 'local'], 'all');
    $legacyProfiles = preg_split('/\s+/', trim($options->task()), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (
        ($options->profile() !== 'all' && $legacyProfiles !== [])
        || array_diff($legacyProfiles, ['cloud', 'local']) !== []
    ) {
        throw new InvalidArgumentException('用法：php bin/06_compare_models.php [--profile=all|cloud|local]');
    }
    $profiles = $options->profile() !== 'all'
        ? [$options->profile()]
        : ($legacyProfiles === [] ? ['cloud', 'local'] : array_values(array_unique($legacyProfiles)));
} catch (Throwable $error) {
    fwrite(STDERR, "参数错误：{$error->getMessage()}\n");
    exit(2);
}
$runId = date('Ymd-His');
$task = <<<'TASK'
先查看工作区，再创建 result.json。文件必须是合法 JSON，并严格包含：
{"topic":"agent","facts":["LLM 生成工具调用意图","Agent runtime 执行真实动作"],"verified":true}
写入后必须读回检查；只有内容完全满足要求时才能结束。
TASK;
$system = <<<'PROMPT'
你是用于模型泛化对比的文件 Agent。必须根据 observation 行动，不能声称执行过未执行的工具。
使用 list_files、write_file、read_file 完成任务。写入后必须读回验证。
不要修改要求中的字段和值，不要创建其他文件。
PROMPT;

$reports = [];
foreach ($profiles as $profile) {
    $workspace = project_path("var/workspaces/compare/{$runId}/{$profile}");
    if (!is_dir($workspace)) {
        mkdir($workspace, 0777, true);
    }

    $startedAt = microtime(true);
    try {
        $llm = LlmFactory::forProfile($profile, "06-compare-{$profile}");
        $tools = new ToolRegistry($llm->logger());
        WorkspaceTools::register($tools, $workspace);
        $agent = new AgentLoop($llm, $tools, maxSteps: 8);
        $answer = $agent->run($task, $system);

        $artifact = is_file($workspace . '/result.json')
            ? file_get_contents($workspace . '/result.json')
            : false;
        $decoded = is_string($artifact) ? json_decode($artifact, true) : null;
        $valid = $decoded === [
            'topic' => 'agent',
            'facts' => ['LLM 生成工具调用意图', 'Agent runtime 执行真实动作'],
            'verified' => true,
        ];
        $metrics = $llm->metrics();
        $durationSeconds = $metrics['duration_ms'] / 1000;

        $reports[] = [
            'profile' => $profile,
            'status' => 'completed',
            'model' => $metrics['model'],
            'artifact_valid' => $valid,
            'llm_calls' => $metrics['calls'],
            'prompt_tokens' => $metrics['prompt_tokens'],
            'completion_tokens' => $metrics['completion_tokens'],
            'total_tokens' => $metrics['total_tokens'],
            'llm_duration_ms' => round($metrics['duration_ms'], 2),
            'wall_duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'observed_output_tokens_per_second' => $durationSeconds > 0
                ? round($metrics['completion_tokens'] / $durationSeconds, 2)
                : null,
            'workspace' => $workspace,
            'answer' => $answer,
        ];
    } catch (Throwable $error) {
        $reports[] = [
            'profile' => $profile,
            'status' => 'failed',
            'wall_duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'workspace' => $workspace,
            'error' => $error->getMessage(),
        ];
    }
}

echo json_encode(
    ['run_id' => $runId, 'task' => $task, 'reports' => $reports],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
) . PHP_EOL;

exit(count(array_filter(
    $reports,
    static fn (array $report): bool => ($report['status'] ?? '') !== 'completed'
        || ($report['artifact_valid'] ?? false) !== true,
)) === 0 ? 0 : 1);
