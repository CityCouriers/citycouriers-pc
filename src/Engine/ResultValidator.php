<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Walidacja wyniku bramki w silniku (nie w bramce) oraz reguła fail-closed 1 (`nothing_checked`).
 */
final class ResultValidator
{
    /**
     * @throws EngineException gdy wynik jest niespójny (kod wyjścia 5)
     */
    public function normalize(Gate $gate, GateResult $result): GateResult
    {
        $this->checkReason($result);
        $this->checkDiagnostics($result);
        $this->checkChecked($gate, $result);

        if ($result->status === GateStatus::Passed && $result->checked !== null && $result->checked->count === 0) {
            return (new ResultFactory())->nothingChecked();
        }

        return $this->sorted($result);
    }

    /**
     * Stabilna kolejność diagnostyk: plik (bajtowo, `null` na początku), linia, kolumna, reguła, komunikat.
     */
    private function sorted(GateResult $result): GateResult
    {
        $diagnostics = $result->diagnostics;
        usort($diagnostics, $this->compare(...));

        return new GateResult($result->status, $result->reason, $result->checked, $diagnostics);
    }

    private function compare(Diagnostic $left, Diagnostic $right): int
    {
        return $this->compareText($left->file, $right->file)
            ?: $this->compareNumber($left->line, $right->line)
            ?: $this->compareNumber($left->column, $right->column)
            ?: $this->compareText($left->rule, $right->rule)
            ?: strcmp($left->message, $right->message);
    }

    private function compareText(?string $left, ?string $right): int
    {
        return match (true) {
            $left === $right => 0,
            $left === null => -1,
            $right === null => 1,
            default => strcmp($left, $right),
        };
    }

    private function compareNumber(?int $left, ?int $right): int
    {
        return ($left ?? PHP_INT_MIN) <=> ($right ?? PHP_INT_MIN);
    }

    /**
     * @throws EngineException
     */
    private function checkReason(GateResult $result): void
    {
        $reason = $result->reason;
        if ($result->status === GateStatus::Passed || $result->status === GateStatus::Failed) {
            if ($reason !== null) {
                throw new EngineException(sprintf('Stan %s nie może mieć powodu.', $result->status->value));
            }

            return;
        }

        if ($reason === null || $reason->message === '') {
            throw new EngineException(sprintf('Stan %s wymaga powodu z komunikatem.', $result->status->value));
        }
        if (($result->status === GateStatus::Skipped) !== $reason->code->isScopeDecision()) {
            throw new EngineException(sprintf('Kod powodu %s nie pasuje do stanu %s (skipped to wyłącznie decyzja zakresu).', $reason->code->value, $result->status->value));
        }
        if (($reason->code === ReasonCode::PreconditionFailed) !== ($reason->blockedBy !== null)) {
            throw new EngineException('Pole blocked_by jest wymagane dokładnie dla kodu precondition_failed.');
        }
    }

    /**
     * @throws EngineException
     */
    private function checkDiagnostics(GateResult $result): void
    {
        $empty = $result->diagnostics === [];
        if ($result->status === GateStatus::Failed && $empty) {
            throw new EngineException('Stan failed wymaga niepustej listy diagnostyk.');
        }
        if ($result->status !== GateStatus::Failed && !$empty) {
            throw new EngineException(sprintf('Stan %s nie może mieć diagnostyk.', $result->status->value));
        }
    }

    /**
     * @throws EngineException
     */
    private function checkChecked(Gate $gate, GateResult $result): void
    {
        if ($result->checked !== null && $result->checked->count < 0) {
            throw new EngineException(sprintf('Bramka „%s” zwróciła ujemną liczbę sprawdzonych jednostek (%d).', $gate->name(), $result->checked->count));
        }
        if ($result->status !== GateStatus::Passed && $result->status !== GateStatus::Failed) {
            return;
        }

        $declared = $gate->checkedUnit();
        if ($declared !== $result->checked?->unit) {
            throw new EngineException(sprintf('Bramka „%s” deklaruje jednostkę „%s”, a wynik niesie inną.', $gate->name(), $declared === null ? 'brak' : $declared->value));
        }
    }
}
