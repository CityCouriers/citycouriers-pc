<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Zmienny stan jednego biegu (wewnętrzny dla `Runner` i `GateExecutor`): błąd silnika, wyjątek w bramce
 * i nazwa bramki wstępnej, która zatrzymała bieg.
 */
final class RunState
{
    public ?string $engineError = null;

    /** @var list<string> awarie fazy finalize (przywrócenie stanu mogło się nie dokonać), zawsze widoczne w `error` */
    public array $finalizeFailures = [];

    public bool $gateException = false;

    public ?string $blockedBy = null;
}
