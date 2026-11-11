<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Kontekst biegu przekazywany bramce. `files` to lista plików ze stage'a albo `null` dla pełnego zakresu
 * (także gdy stage jest pusty: pusty stage nie oznacza „brak bramek”). `diffBase` to pełny OID z `--base`
 * albo `null`; bramki nie rozwiązują rewizji same.
 */
final readonly class GateContext
{
    /**
     * @param list<string>|null $files
     * @param array<mixed>      $config zawartość `pc.json`
     */
    public function __construct(
        public RunScope $scope,
        public ?array $files,
        public ?string $diffBase,
        public string $root,
        public array $config = [],
    ) {}
}
