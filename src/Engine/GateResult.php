<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Wynik jednej bramki. Spójność (powód dla `skipped` i `not_scanned`, diagnostyki dla `failed`) sprawdza silnik
 * w `ResultValidator`, a niespójny wynik kończy bieg kodem 5.
 */
final readonly class GateResult
{
    /**
     * @param list<Diagnostic> $diagnostics
     */
    public function __construct(
        public GateStatus $status,
        public ?Reason $reason = null,
        public ?Checked $checked = null,
        public array $diagnostics = [],
    ) {}
}
