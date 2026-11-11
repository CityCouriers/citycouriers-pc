<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Wszystko, czego runner potrzebuje do biegu: włączone bramki i kontekst.
 */
final readonly class PreparedRun
{
    /**
     * @param list<string> $enabled
     */
    public function __construct(
        public array $enabled,
        public GateContext $context,
    ) {}
}
