<?php

declare(strict_types=1);

namespace PlainShow\Install;

use RuntimeException;

/**
 * Runs commands for the installer. Everything a command prints goes to the
 * log (/var/log/plainshow-install.log), never to the screen; a failure stops
 * with the command's last lines. Secrets are passed on standard input or in
 * files, never as arguments, so they stay out of the log and `ps`.
 */
final class Shell
{
    public const LOG = '/var/log/plainshow-install.log';

    /** Values that never appear in the log, such as passwords passed to a command. */
    private static array $hidden = [];

    public static function hide(string ...$secrets): void
    {
        foreach ($secrets as $secret) {
            if (strlen($secret) >= 6) {
                self::$hidden[$secret] = true;
            }
        }
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env passed in the environment, so it is in neither the log nor `ps`
     */
    public static function run(array $command, ?string $cwd = null, array $env = [], ?string $input = null, ?string $as = null, int $timeout = 3600): string
    {
        if ($as !== null) {
            $command = ['runuser', '-m', '-u', $as, '--', ...$command];
        }
        self::log('+ '.($cwd ? "(in $cwd) " : '').implode(' ', array_map(fn ($part) => preg_match('/^[\w.\/:=@%+,-]+$/', $part) ? $part : escapeshellarg($part), $command)));

        $environment = array_merge(getenv(), ['LC_ALL' => 'C.UTF-8', 'DEBIAN_FRONTEND' => 'noninteractive', 'COMPOSER_ALLOW_SUPERUSER' => '1'], $env);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, $environment);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start '.$command[0].'.');
        }
        if ($input !== null) {
            fwrite($pipes[0], $input);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        $output = '';
        $started = time();
        while (true) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk !== false && $chunk !== '') {
                $output .= $chunk;
                self::log($chunk, false);
            }
            $status = proc_get_status($process);
            if (! $status['running']) {
                $rest = stream_get_contents($pipes[1]);
                if ($rest !== false && $rest !== '') {
                    $output .= $rest;
                    self::log($rest, false);
                }
                break;
            }
            if (time() - $started > $timeout) {
                proc_terminate($process);
                throw new RuntimeException($command[0].' took longer than '.intdiv($timeout, 60).' minutes and was stopped.');
            }
            usleep(50000);
        }
        fclose($pipes[1]);
        $code = $status['exitcode'];
        proc_close($process);
        if ($code !== 0) {
            $tail = implode("\n", array_slice(preg_split('/\R/', trim($output)) ?: [], -15));
            throw new CommandFailed(implode(' ', array_slice($command, 0, 6)), $code, $tail);
        }

        return $output;
    }

    /** Runs a command and tells whether it worked, without stopping. */
    public static function ok(array $command, ?string $cwd = null, ?string $as = null): bool
    {
        try {
            self::run($command, $cwd, as: $as, timeout: 600);

            return true;
        } catch (CommandFailed) {
            return false;
        }
    }

    public static function exists(string $program): bool
    {
        return self::ok(['sh', '-c', 'command -v '.escapeshellarg($program)]);
    }

    public static function log(string $text, bool $line = true): void
    {
        $text = self::$hidden ? str_replace(array_keys(self::$hidden), '******', $text) : $text;
        @file_put_contents(self::LOG, $line ? '['.date('H:i:s')."] $text\n" : $text, FILE_APPEND);
    }

    /** Writes a file only when it changes; returns whether it changed. */
    public static function write(string $path, string $content, int $mode = 0644, ?string $owner = null, ?string $group = null): bool
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $changed = ! is_file($path) || file_get_contents($path) !== $content;
        if ($changed) {
            $tmp = $path.'.plainshow-new';
            file_put_contents($tmp, $content);
            chmod($tmp, $mode);
            rename($tmp, $path);
            self::log("wrote $path");
        }
        chmod($path, $mode);
        if ($owner !== null) {
            chown($path, $owner);
        }
        if ($group !== null) {
            chgrp($path, $group);
        }

        return $changed;
    }

    /** A random secret of letters and digits. */
    public static function secret(int $length = 48): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $secret;
    }
}
