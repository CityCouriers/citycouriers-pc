<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Wejście klasyfikatora ustalone z gita: lista ścieżek albo powód, dla którego listy nie da się ustalić
 * (`unresolvedReason` różne od `null` wymusza pełny zestaw bramek).
 */
final readonly class ScopeInput
{
    /**
     * @param list<string> $paths
     */
    public function __construct(
        public array $paths,
        public bool $specialModes,
        public string $description,
        public ?string $unresolvedReason = null,
    ) {}
}
