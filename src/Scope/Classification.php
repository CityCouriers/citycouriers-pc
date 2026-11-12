<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Wynik czystej klasyfikacji: co MOŻNA pominąć, nie co się stanie (to rozstrzyga polityka egzekwowania).
 * `full` to pełny zestaw bramek (każda niepewność), `cause` mówi dlaczego.
 */
final readonly class Classification
{
    /**
     * @param list<string> $gates   bramki do uruchomienia (w kolejności zestawu reguł)
     * @param list<Skip>   $skipped bramki, które nic by nie sprawdziły
     */
    public function __construct(
        public array $gates,
        public array $skipped,
        public bool $full,
        public FullCause $cause,
        public string $reason,
    ) {}
}
