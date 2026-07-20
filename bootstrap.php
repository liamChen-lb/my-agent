<?php

declare(strict_types=1);

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
} else {
    // 保持零安装可运行；IDE 和标准环境通过 composer.json 的 PSR-4 映射识别类关系。
    spl_autoload_register(static function (string $class): void {
        $prefix = 'DemoAgent\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
        $file = __DIR__ . '/src/' . $relative . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

/**
 * 读取项目根目录 .env；已由 Shell/进程设置的变量优先，不会被文件覆盖。
 */
function load_dot_env(string $file): void
{
    if (!is_file($file)) {
        return;
    }

    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = substr($line, 7);
        }

        $separator = strpos($line, '=');
        if ($separator === false) {
            continue;
        }
        $name = trim(substr($line, 0, $separator));
        if (preg_match('/^[A-Z_][A-Z0-9_]*$/i', $name) !== 1 || getenv($name) !== false) {
            continue;
        }

        $value = trim(substr($line, $separator + 1));
        if (strlen($value) >= 2) {
            $quote = $value[0];
            if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
                $value = substr($value, 1, -1);
                if ($quote === '"') {
                    $value = stripcslashes($value);
                }
            }
        }

        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
    }
}

load_dot_env(__DIR__ . '/.env');

function env(string $name, ?string $default = null): ?string
{
    $value = getenv($name);

    return $value === false ? $default : $value;
}

$timezone = env('APP_TIMEZONE', 'Asia/Shanghai') ?? 'Asia/Shanghai';
if (!in_array($timezone, timezone_identifiers_list(), true)) {
    throw new RuntimeException("无效的 APP_TIMEZONE：{$timezone}");
}
date_default_timezone_set($timezone);

function project_path(string $path = ''): string
{
    return __DIR__ . ($path === '' ? '' : DIRECTORY_SEPARATOR . ltrim($path, '/'));
}
