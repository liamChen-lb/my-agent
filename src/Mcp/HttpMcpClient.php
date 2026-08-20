<?php

declare(strict_types=1);

namespace DemoAgent\Mcp;

use DemoAgent\Observability\TranscriptLogger;
use DemoAgent\Tools\CallableTool;
use DemoAgent\Tools\ToolRegistry;

/**
 * MCP Streamable HTTP Client 的教学型最小实现。
 *
 * 支持 initialize、notifications/initialized、tools/list、tools/call，
 * 同时接受 application/json 和 text/event-stream 响应。
 */
final class HttpMcpClient
{
    private int $nextId = 1;

    private ?string $protocolVersion = null;

    private ?string $sessionId = null;

    /** @param array<string, string> $headers */
    public function __construct(
        private readonly string $url,
        private readonly array $headers,
        private readonly TranscriptLogger $logger,
        private readonly int $timeoutSeconds = 30,
    ) {
        $parts = parse_url($this->url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('MCP HTTP URL 必须使用 http 或 https');
        }
        if ($this->timeoutSeconds < 1) {
            throw new \InvalidArgumentException('MCP HTTP timeout 必须大于 0');
        }
        foreach ($this->headers as $name => $value) {
            if (
                preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name) !== 1
                || str_contains($value, "\r")
                || str_contains($value, "\n")
            ) {
                throw new \InvalidArgumentException('MCP HTTP 自定义 Header 无效');
            }
        }

        $result = $this->request('initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'php-agent-demo', 'version' => '1.0.0'],
        ]);
        $this->protocolVersion = is_string($result['protocolVersion'] ?? null)
            ? $result['protocolVersion']
            : '2025-11-25';
        $this->notify('notifications/initialized');
    }

    public function registerTools(ToolRegistry $registry, string $prefix = 'mcp_'): void
    {
        $result = $this->request('tools/list');
        foreach (($result['tools'] ?? []) as $tool) {
            if (!is_array($tool) || !is_string($tool['name'] ?? null)) {
                continue;
            }
            $remoteName = $tool['name'];
            $localName = $prefix . preg_replace('/[^A-Za-z0-9_-]+/', '_', $remoteName);
            $schema = is_array($tool['inputSchema'] ?? null)
                ? $this->normalizeSchema($tool['inputSchema'])
                : ['type' => 'object', 'properties' => new \stdClass()];
            $registry->register(new CallableTool(
                $localName,
                '[MCP HTTP: ' . $remoteName . '] ' . (string) ($tool['description'] ?? ''),
                $schema,
                fn (array $arguments): array => $this->request('tools/call', [
                    'name' => $remoteName,
                    'arguments' => $arguments,
                ]),
            ));
        }
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function request(string $method, array $params = []): array
    {
        $id = $this->nextId++;
        $response = $this->send([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params === [] ? new \stdClass() : $params,
        ], true);
        if (!is_array($response) || ($response['id'] ?? null) !== $id) {
            throw new \RuntimeException('MCP HTTP Server 返回了无效响应');
        }
        if (isset($response['error'])) {
            throw new \RuntimeException(
                'MCP HTTP 错误：'
                . json_encode($response['error'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            );
        }

        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }

    private function notify(string $method): void
    {
        $this->send([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => new \stdClass(),
        ], false);
    }

    /** @param array<string, mixed> $message @return array<string, mixed>|null */
    private function send(array $message, bool $expectsResponse): ?array
    {
        $payload = json_encode(
            $message,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_THROW_ON_ERROR,
        );
        $responseHeaders = [];
        $requestHeaders = [
            'Accept: application/json, text/event-stream',
            'Content-Type: application/json',
        ];
        if ($this->protocolVersion !== null) {
            $requestHeaders[] = 'MCP-Protocol-Version: ' . $this->protocolVersion;
        }
        if ($this->sessionId !== null) {
            $requestHeaders[] = 'Mcp-Session-Id: ' . $this->sessionId;
        }
        foreach ($this->headers as $name => $value) {
            $requestHeaders[] = "{$name}: {$value}";
        }

        $this->logger->record('mcp.http.request', [
            'url' => $this->url,
            'message' => $message,
            'custom_header_names' => array_keys($this->headers),
        ]);
        $curl = curl_init($this->url);
        if ($curl === false) {
            throw new \RuntimeException('无法初始化 MCP HTTP curl');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            },
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new \RuntimeException("MCP HTTP 请求失败：{$error}");
        }
        if (isset($responseHeaders['mcp-session-id'])) {
            $this->sessionId = $responseHeaders['mcp-session-id'];
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("MCP HTTP Server 返回 HTTP {$status}：" . $this->truncate($body));
        }
        if (!$expectsResponse && trim($body) === '') {
            $this->logger->record('mcp.http.response', [
                'http_status' => $status,
                'notification' => true,
            ]);

            return null;
        }

        $decoded = str_contains(strtolower($contentType), 'text/event-stream')
            ? $this->decodeEventStream($body, $message['id'] ?? null)
            : json_decode($body, true);
        $this->logger->record('mcp.http.response', [
            'http_status' => $status,
            'content_type' => $contentType,
            'message' => $decoded ?? $this->truncate($body),
        ]);
        if (!$expectsResponse) {
            return null;
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('MCP HTTP Server 未返回合法 JSON-RPC');
        }

        return $decoded;
    }

    /** @return array<string, mixed>|null */
    private function decodeEventStream(string $body, mixed $expectedId): ?array
    {
        foreach (preg_split('/\R\R+/', trim($body)) ?: [] as $event) {
            $dataLines = [];
            foreach (preg_split('/\R/', $event) ?: [] as $line) {
                if (str_starts_with($line, 'data:')) {
                    $dataLines[] = ltrim(substr($line, 5));
                }
            }
            if ($dataLines === []) {
                continue;
            }
            $data = implode("\n", $dataLines);
            if ($data === '[DONE]') {
                continue;
            }
            $decoded = json_decode($data, true);
            if (is_array($decoded) && ($expectedId === null || ($decoded['id'] ?? null) === $expectedId)) {
                return $decoded;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $schema @return array<string, mixed> */
    private function normalizeSchema(array $schema): array
    {
        if (($schema['type'] ?? null) === 'object') {
            if (!isset($schema['properties']) || $schema['properties'] === []) {
                $schema['properties'] = new \stdClass();
            } elseif (is_array($schema['properties'])) {
                foreach ($schema['properties'] as $name => $property) {
                    if (is_array($property)) {
                        $schema['properties'][$name] = $this->normalizeSchema($property);
                    }
                }
            }
        }
        if (is_array($schema['items'] ?? null)) {
            $schema['items'] = $this->normalizeSchema($schema['items']);
        }

        return $schema;
    }

    private function truncate(string $value, int $maxChars = 1000): string
    {
        return mb_strlen($value) > $maxChars ? mb_substr($value, 0, $maxChars) . '…' : $value;
    }
}
