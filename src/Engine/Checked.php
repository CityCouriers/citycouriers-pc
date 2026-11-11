<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Dowód, że bramka coś sprawdziła: jednostka i liczba.
 */
final readonly class Checked
{
    public function __construct(
        public CheckedUnit $unit,
        public int $count,
    ) {}
}
