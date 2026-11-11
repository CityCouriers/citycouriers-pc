<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

use CityCouriers\Pc\Scope\Profile;

/**
 * Decyzja zakresu (#40) dla bramki silnika: w profilu `local-narrow` bramka bez plików wyzwalających jest
 * `skipped` / `no_matching_files`. Profil `enforce-all` i brak wiarygodnej listy plików (`scopePaths` równe `null`)
 * nigdy nie pomijają bramki.
 */
final readonly class ScopeGuard
{
    public function __construct(private ResultFactory $results = new ResultFactory()) {}

    public function skip(Gate $gate, GateContext $context): ?GateResult
    {
        $triggers = $gate->triggers();
        if ($context->profile !== Profile::LocalNarrow || $context->scopePaths === null || $triggers->matches($context->scopePaths)) {
            return null;
        }

        return $this->results->skipped(ReasonCode::NoMatchingFiles, $triggers->skipReason);
    }
}
