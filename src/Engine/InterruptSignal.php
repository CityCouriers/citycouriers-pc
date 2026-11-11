<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Źródło informacji o przerwaniu biegu. Wstrzykiwane, żeby silnik dało się testować bez prawdziwych sygnałów.
 */
interface InterruptSignal
{
    /**
     * Numer sygnału (2 = SIGINT, 15 = SIGTERM), gdy bieg został przerwany; w przeciwnym razie `null`.
     */
    public function signal(): ?int;
}
