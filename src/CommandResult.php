<?php

declare(strict_types=1);

namespace CityCouriers\Pc;

/**
 * Wynik polecenia: kod wyjścia i teksty dla strumieni. Wejście CLI tylko je wypisuje,
 * dzięki czemu polecenia da się testować bez procesu potomnego.
 */
final readonly class CommandResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout = '',
        public string $stderr = '',
    ) {}
}
