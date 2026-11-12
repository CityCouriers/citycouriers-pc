<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Decyzja po zastosowaniu polityki: bramki do uruchomienia, bramki pominięte (tylko `local-narrow`) i informacja,
 * co lokalnie zostałoby pominięte (tylko `enforce-all`, nic z tej listy nie jest pomijane).
 */
final readonly class Selection
{
    /**
     * @param list<string> $gates
     * @param list<Skip>   $skipped
     * @param list<Skip>   $wouldSkip
     */
    public function __construct(
        public Profile $profile,
        public array $gates,
        public array $skipped,
        public array $wouldSkip,
        public bool $full,
        public string $reason,
    ) {}
}
