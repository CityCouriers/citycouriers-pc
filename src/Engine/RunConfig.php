<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Konfiguracja `pc run`: zawartość `pc.json` i lista włączonych bramek (niepusta, zob. `RunConfigLoader`).
 */
final readonly class RunConfig
{
    /**
     * @param array<mixed> $raw
     * @param list<string> $enabled
     */
    public function __construct(
        public array $raw,
        public array $enabled,
    ) {}
}
