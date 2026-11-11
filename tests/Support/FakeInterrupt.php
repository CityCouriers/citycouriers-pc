<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Tests\Support;

use CityCouriers\Pc\Engine\InterruptSignal;

/**
 * Sygnał przerwania sterowany z testu (bez prawdziwych sygnałów procesu).
 */
final class FakeInterrupt implements InterruptSignal
{
    private ?int $signal = null;

    public function raise(int $signal = 2): void
    {
        $this->signal ??= $signal;
    }

    public function signal(): ?int
    {
        return $this->signal;
    }
}
