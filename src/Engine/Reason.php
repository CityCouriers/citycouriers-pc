<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Powód stanu `skipped` albo `not_scanned`. `blockedBy` to nazwa bramki wstępnej przy `precondition_failed`.
 */
final readonly class Reason
{
    public function __construct(
        public ReasonCode $code,
        public string $message,
        public ?string $blockedBy = null,
    ) {}
}
