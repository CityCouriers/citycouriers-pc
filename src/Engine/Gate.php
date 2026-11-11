<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Kontrakt bramki. Najmniejszy interfejs, który obsługuje bramkę; opcjonalna faza po biegu to `FinalizingGate`.
 * Opis dla autorów bramek: docs/bramki.md.
 */
interface Gate
{
    /** Nazwa z `pc.json` (jedna ze znanych nazw, zob. `ConfigValidator::GATES`). */
    public function name(): string;

    public function mode(): GateMode;

    /** Bramka wstępna (reguła 6): jej `failed` albo `not_scanned` zatrzymuje kolejne bramki. Tylko blokująca. */
    public function precondition(): bool;

    /** Jednostka liczona w `checked`, albo `null` = jawny brak jednostki. */
    public function checkedUnit(): ?CheckedUnit;

    public function triggers(): GateTriggers;

    public function run(GateContext $context): GateResult;
}
