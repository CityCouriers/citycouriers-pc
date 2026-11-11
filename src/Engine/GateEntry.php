<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Wpis jednej włączonej bramki w raporcie (także pominiętej i nieuruchomionej).
 */
final readonly class GateEntry
{
    public function __construct(
        public string $name,
        public GateMode $mode,
        public GateResult $result,
        public ?int $durationMs = null,
    ) {}
}
