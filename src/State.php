<?php

declare(strict_types=1);

namespace PlainShow\Install;

/**
 * What the installer knows between runs: the answers, the secrets it made
 * and the steps that are done. Kept in /etc/plainshow/install/state.json,
 * readable by root only, so a failed install continues where it stopped and
 * a second run never makes new passwords for an existing database.
 */
final class State
{
    public const FILE = '/etc/plainshow/install/state.json';

    /** @var array<string, mixed> */
    private array $data;

    public function __construct(private readonly string $file = self::FILE)
    {
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $this->data = is_array($data) ? $data : ['answers' => [], 'secrets' => [], 'done' => [], 'versions' => []];
        // No secret the installer knows ever reaches the log.
        Shell::hide((string) ($this->data['answers']['admin']['password'] ?? ''), ...array_values(array_filter($this->data['secrets'], 'is_string')));
    }

    public function answers(): array
    {
        return $this->data['answers'];
    }

    public function hasAnswers(): bool
    {
        return ! empty($this->data['answers']['domain']);
    }

    public function setAnswers(array $answers): void
    {
        $this->data['answers'] = $answers;
        $this->save();
        Shell::hide((string) ($answers['admin']['password'] ?? ''));
    }

    public function answer(string $key, mixed $default = null): mixed
    {
        return $this->data['answers'][$key] ?? $default;
    }

    /** A secret by name, made once and kept. */
    public function secret(string $name, int $length = 48): string
    {
        if (empty($this->data['secrets'][$name])) {
            $this->data['secrets'][$name] = Shell::secret($length);
            $this->save();
            Shell::hide($this->data['secrets'][$name]);
        }

        return $this->data['secrets'][$name];
    }

    public function setSecret(string $name, string $value): void
    {
        $this->data['secrets'][$name] = $value;
        $this->save();
        Shell::hide($value);
    }

    public function hasSecret(string $name): bool
    {
        return ! empty($this->data['secrets'][$name]);
    }

    public function forget(string $path): void
    {
        [$section, $key] = explode('.', $path, 2);
        unset($this->data[$section][$key]);
        $this->save();
    }

    public function done(string $step): bool
    {
        return in_array($step, $this->data['done'], true);
    }

    public function markDone(string $step): void
    {
        if (! $this->done($step)) {
            $this->data['done'][] = $step;
            $this->save();
        }
    }

    public function version(string $app): ?string
    {
        return $this->data['versions'][$app] ?? null;
    }

    public function setVersion(string $app, string $version): void
    {
        $this->data['versions'][$app] = $version;
        $this->save();
    }

    private function save(): void
    {
        $dir = dirname($this->file);
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $tmp = $this->file.'.new';
        file_put_contents($tmp, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod($tmp, 0600);
        rename($tmp, $this->file);
    }
}
