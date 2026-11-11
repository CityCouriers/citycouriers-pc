<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Wynik `ScopeDecider`: decyzja po polityce i wejście, z którego powstała (do opisu).
 */
final readonly class ScopeDecision
{
    public function __construct(
        public Selection $selection,
        public ScopeInput $input,
    ) {}
}
