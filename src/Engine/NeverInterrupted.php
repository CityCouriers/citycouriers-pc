<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

final class NeverInterrupted implements InterruptSignal
{
    public function signal(): ?int
    {
        return null;
    }
}
