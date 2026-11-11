<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Wynik polecenia gita: kod wyjścia i stdout.
 */
final readonly class GitOutput
{
    public function __construct(
        public int $code,
        public string $stdout,
    ) {}
}
