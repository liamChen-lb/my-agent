<?php

declare(strict_types=1);

namespace DemoAgent\Llm;

use DemoAgent\Observability\TranscriptLogger;

final class LlmClient
{
    /** @var array{calls: int, prompt_tokens: int, completion_tokens: int, total_tokens: int, duration_ms: float} */
    private array $metrics = [
        'calls' => 0,
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'total_tokens' => 0,
        'duration_ms' => 0.0,
    ];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly TranscriptLogger $logger,
        private readonly int $timeoutSeconds = 120,
    ) {
        if ($this->apiKey === '') {
            throw new \InvalidArgumentException('缺少 LLM_API_KEY 环境变量');
        }
    }

    /**
     * 调用 OpenAI-compatible Chat Completions API。
     *
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     * @param array<string, mixed> $options
     * @return array<string, mixed> assistant message
     */
    public function complete(
        array $messages,
        array $tools = [],
        array $options = [],
        string $purpose = 'agent',
    ): array {
        $payload = array_merge([
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.2,
        ], $options);

        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = $payload['tool_choice'] ?? 'auto';
        }

        $this->logger->record('llm.request', [
            'purpose' => $purpose,
            'url' => $this->endpoint(),
            'payload' => $payload,
        ]);

        $curl = curl_init($this->endpoint());
        if ($curl === false) {
            throw new \RuntimeException('无法初始化 curl');
        }

        $startedAt = microtime(true);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR,
            ),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        $body = curl_exec($curl);
        $durationMs = round((microtime(true) - $startedAt) * 1000, 2);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new \RuntimeException("LLM 请求失败：{$error}");
        }

        /** @var mixed $decoded */
        $decoded = json_decode($body, true);
        $usage = is_array($decoded) && is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        $this->metrics['calls']++;
        $this->metrics['prompt_tokens'] += (int) ($usage['prompt_tokens'] ?? 0);
        $this->metrics['completion_tokens'] += (int) ($usage['completion_tokens'] ?? 0);
        $this->metrics['total_tokens'] += (int) ($usage['total_tokens'] ?? 0);
        $this->metrics['duration_ms'] += $durationMs;
        $this->logger->record('llm.response', [
            'purpose' => $purpose,
            'http_status' => $status,
            'duration_ms' => $durationMs,
            'body' => $decoded ?? $body,
        ]);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("LLM API 返回 HTTP {$status}：{$body}");
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('LLM API 未返回合法 JSON');
        }

        $message = $decoded['choices'][0]['message'] ?? null;
        if (!is_array($message)) {
            throw new \RuntimeException('LLM API 响应缺少 choices[0].message');
        }

        return $message;
    }

    public function logger(): TranscriptLogger
    {
        return $this->logger;
    }

    /** @return array{model: string, calls: int, prompt_tokens: int, completion_tokens: int, total_tokens: int, duration_ms: float} */
    public function metrics(): array
    {
        return ['model' => $this->model, ...$this->metrics];
    }

    private function endpoint(): string
    {
        return rtrim($this->baseUrl, '/') . '/chat/completions';
    }
}
