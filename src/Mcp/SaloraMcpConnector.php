<?php

declare(strict_types=1);

namespace DemoAgent\Mcp;

use DemoAgent\Observability\TranscriptLogger;
use DemoAgent\Tools\ToolRegistry;

final class SaloraMcpConnector
{
    public static function registerFromEnvironment(
        ToolRegistry $registry,
        TranscriptLogger $logger,
    ): ?HttpMcpClient {
        if (!self::enabled(\env('SALORA_MCP_ENABLED', '0'))) {
            return null;
        }

        $token = trim((string) \env('SALORA_MCP_TOKEN', ''));
        if ($token === '') {
            throw new \InvalidArgumentException('启用 Salora MCP 时必须配置 SALORA_MCP_TOKEN');
        }
        $header = trim((string) \env('SALORA_MCP_TOKEN_HEADER', 'X-CRM-Authorization'));
        $authorization = preg_match('/^Bearer\s+/i', $token) === 1 ? $token : "Bearer {$token}";
        $client = new HttpMcpClient(
            (string) \env('SALORA_MCP_URL', 'http://127.0.0.1:3000/mcp'),
            [$header => $authorization],
            $logger,
            (int) (\env('SALORA_MCP_TIMEOUT', '30') ?? '30'),
        );
        $client->registerTools($registry);

        return $client;
    }

    private static function enabled(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
