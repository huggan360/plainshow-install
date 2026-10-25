<?php

declare(strict_types=1);

namespace PlainShow\Install;

use RuntimeException;

/** A command the installer ran ended with an error; carries its last lines. */
final class CommandFailed extends RuntimeException
{
    public function __construct(public readonly string $command, public readonly int $exitCode, public readonly string $tail)
    {
        parent::__construct("$command failed (exit code $exitCode).");
    }
}
