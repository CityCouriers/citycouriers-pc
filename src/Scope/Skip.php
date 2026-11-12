<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Bramka, którą klasyfikator uznał za zbędną dla zmienionych plików, wraz z powodem.
 */
final readonly class Skip
{
    public function __construct(
        public string $gate,
        public string $reason,
    ) {}
}
