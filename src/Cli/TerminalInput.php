<?php

declare(strict_types=1);

namespace DemoAgent\Cli;

/**
 * 最小终端行输入器：在交互 TTY 中保护提示符不被退格擦除。
 */
final class TerminalInput
{
    public static function readLine(string $prompt): ?string
    {
        if (!stream_isatty(STDIN) || !stream_isatty(STDOUT)) {
            fwrite(STDOUT, $prompt);
            $line = fgets(STDIN);

            return $line === false ? null : rtrim($line, "\r\n");
        }

        if (function_exists('readline')) {
            $line = readline(self::readlinePrompt($prompt));
            if ($line === false) {
                return null;
            }
            if ($line !== '' && function_exists('readline_add_history')) {
                readline_add_history($line);
            }

            return $line;
        }

        $settings = trim((string) @shell_exec('stty -g < /dev/tty 2>/dev/null'));
        if ($settings === '') {
            fwrite(STDOUT, $prompt);
            $line = fgets(STDIN);

            return $line === false ? null : rtrim($line, "\r\n");
        }

        @shell_exec('stty raw -echo < /dev/tty 2>/dev/null');
        $buffer = '';
        $result = null;
        $printNewline = true;
        fwrite(STDOUT, $prompt);

        try {
            while (true) {
                $byte = fread(STDIN, 1);
                if ($byte === false || $byte === '') {
                    $printNewline = false;
                    break;
                }

                $code = ord($byte);
                if ($byte === "\r" || $byte === "\n") {
                    $result = $buffer;
                    break;
                }
                if ($code === 3) { // Ctrl-C
                    fwrite(STDOUT, '^C');
                    $result = '';
                    break;
                }
                if ($code === 4) { // Ctrl-D
                    $result = $buffer === '' ? null : $buffer;
                    break;
                }
                if ($code === 8 || $code === 127) {
                    $buffer = self::removeLastCharacter($buffer);
                    self::redraw($prompt, $buffer);
                    continue;
                }
                if ($code === 27) { // 忽略方向键等 ANSI escape sequence
                    self::discardEscapeSequence();
                    self::redraw($prompt, $buffer);
                    continue;
                }
                if ($code < 32) {
                    continue;
                }

                $character = $byte . self::readUtf8Continuation($code);
                $buffer .= $character;
                fwrite(STDOUT, $character);
            }
        } finally {
            @shell_exec('stty ' . escapeshellarg($settings) . ' < /dev/tty 2>/dev/null');
        }

        if ($printNewline) {
            fwrite(STDOUT, PHP_EOL);
        }

        return $result;
    }

    public static function removeLastCharacter(string $value): string
    {
        $index = strlen($value) - 1;
        if ($index < 0) {
            return '';
        }

        while ($index > 0 && (ord($value[$index]) & 0xC0) === 0x80) {
            $index--;
        }

        return substr($value, 0, $index);
    }

    public static function readlinePrompt(string $prompt): string
    {
        return preg_replace_callback(
            '/\x1B\[[0-?]*[ -\/]*[@-~]/',
            static fn (array $match): string => "\001{$match[0]}\002",
            $prompt,
        ) ?? $prompt;
    }

    private static function redraw(string $prompt, string $buffer): void
    {
        fwrite(STDOUT, "\r\033[2K{$prompt}{$buffer}");
    }

    private static function readUtf8Continuation(int $firstByte): string
    {
        $remaining = match (true) {
            ($firstByte & 0xE0) === 0xC0 => 1,
            ($firstByte & 0xF0) === 0xE0 => 2,
            ($firstByte & 0xF8) === 0xF0 => 3,
            default => 0,
        };
        $bytes = '';
        while ($remaining > 0) {
            $next = fread(STDIN, 1);
            if ($next === false || $next === '') {
                break;
            }
            $bytes .= $next;
            $remaining--;
        }

        return $bytes;
    }

    private static function discardEscapeSequence(): void
    {
        stream_set_blocking(STDIN, false);
        usleep(2_000);
        while (($byte = fread(STDIN, 1)) !== false && $byte !== '') {
            if (ctype_alpha($byte) || $byte === '~') {
                break;
            }
        }
        stream_set_blocking(STDIN, true);
    }
}
