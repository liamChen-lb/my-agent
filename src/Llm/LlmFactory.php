<?php

declare(strict_types=1);

namespace DemoAgent\Llm;

use DemoAgent\Observability\TranscriptLogger;

final class LlmFactory
{
    public static function fromEnvironment(string $session): LlmClient
    {
        $model = \env('LLM_MODEL_ID', \env('LLM_MODEL', 'deepseek-chat')) ?? '';

        return self::create(
            $session,
            'default',
            \env('LLM_BASE_URL', 'https://api.deepseek.com/v1') ?? '',
            \env('LLM_API_KEY', '') ?? '',
            $model,
            (int) (\env('LLM_TIMEOUT', '120') ?? '120'),
        );
    }

    public static function forProfile(string $profile, string $session): LlmClient
    {
        return match (strtolower($profile)) {
            'default' => self::fromEnvironment($session),
            'cloud' => self::create(
                $session,
                'cloud',
                \env('CLOUD_LLM_BASE_URL', \env('LLM_BASE_URL', 'https://api.deepseek.com/v1')) ?? '',
                \env('CLOUD_LLM_API_KEY', \env('LLM_API_KEY', '')) ?? '',
                \env(
                    'CLOUD_LLM_MODEL_ID',
                    \env('CLOUD_LLM_MODEL', \env('LLM_MODEL_ID', \env('LLM_MODEL', 'deepseek-chat'))),
                ) ?? '',
                (int) (\env('CLOUD_LLM_TIMEOUT', \env('LLM_TIMEOUT', '120')) ?? '120'),
            ),
            'local' => self::create(
                $session,
                'local',
                \env('LOCAL_LLM_BASE_URL', 'http://127.0.0.1:11434/v1') ?? '',
                \env('LOCAL_LLM_API_KEY', 'ollama') ?? 'ollama',
                \env('LOCAL_LLM_MODEL_ID', \env('LOCAL_LLM_MODEL', 'qwen3.6:35b')) ?? '',
                (int) (\env('LOCAL_LLM_TIMEOUT', '600') ?? '600'),
            ),
            default => throw new \InvalidArgumentException("未知 LLM profile：{$profile}"),
        };
    }

    private static function create(
        string $session,
        string $profile,
        string $baseUrl,
        string $apiKey,
        string $model,
        int $timeout,
    ): LlmClient {
        $logFile = \project_path('var/logs/' . $session . '-' . date('Ymd-His') . '.jsonl');
        $trace = \env('AGENT_TRACE', '1') !== '0';
        $logger = new TranscriptLogger($logFile, $trace);
        $logger->record('session.started', [
            'session' => $session,
            'profile' => $profile,
            'log_file' => $logFile,
            'base_url' => $baseUrl,
            'model' => $model,
        ]);

        return new LlmClient(
            $baseUrl,
            $apiKey,
            $model,
            $logger,
            $timeout,
        );
    }
}
