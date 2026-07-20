<?php

declare(strict_types=1);

namespace DemoAgent\Mcp;

use DemoAgent\Observability\TranscriptLogger;
use DemoAgent\Tools\CallableTool;
use DemoAgent\Tools\ToolRegistry;

final class McpClient
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    private int $nextId = 1;

    public function __construct(
        array $command,
        private readonly TranscriptLogger $logger,
        ?string $workingDirectory = null,
    ) {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/stderr', 'a'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $workingDirectory);
        if (!is_resource($process)) {
            throw new \RuntimeException('无法启动 MCP Server');
        }
        $this->process = $process;
        $this->pipes = $pipes;
        stream_set_timeout($this->pipes[1], 30);

        $this->request('initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'php-agent-demo', 'version' => '1.0.0'],
        ]);
        $this->notify('notifications/initialized');
    }

    public function __destruct()
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    public function registerTools(ToolRegistry $registry, string $prefix = 'mcp_'): void
    {
        $result = $this->request('tools/list');
        foreach (($result['tools'] ?? []) as $tool) {
            if (!is_array($tool) || !is_string($tool['name'] ?? null)) {
                continue;
            }
            $remoteName = $tool['name'];
            $schema = is_array($tool['inputSchema'] ?? null)
                ? $this->normalizeSchema($tool['inputSchema'])
                : ['type' => 'object', 'properties' => new \stdClass()];
            $registry->register(new CallableTool(
                $prefix . $remoteName,
                '[MCP] ' . (string) ($tool['description'] ?? ''),
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
        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
        $line = fgets($this->pipes[1]);
        if ($line === false) {
            $metadata = stream_get_meta_data($this->pipes[1]);
            throw new \RuntimeException('MCP Server 未响应' . (($metadata['timed_out'] ?? false) ? '（超时）' : ''));
        }

        $response = json_decode($line, true);
        $this->logger->record('mcp.response', ['method' => $method, 'message' => $response ?? $line]);
        if (!is_array($response) || ($response['id'] ?? null) !== $id) {
            throw new \RuntimeException('MCP Server 返回了无效响应');
        }
        if (isset($response['error'])) {
            throw new \RuntimeException('MCP 错误：' . json_encode($response['error'], JSON_UNESCAPED_UNICODE));
        }

        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }

    private function notify(string $method): void
    {
        $this->send(['jsonrpc' => '2.0', 'method' => $method, 'params' => new \stdClass()]);
    }

    /** @param array<string, mixed> $message */
    private function send(array $message): void
    {
        $this->logger->record('mcp.request', ['message' => $message]);
        fwrite(
            $this->pipes[0],
            json_encode(
                $message,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR,
            ) . "\n",
        );
        fflush($this->pipes[0]);
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
}
