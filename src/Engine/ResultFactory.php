<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Fabryka wyników bramki (metody niestatyczne, żeby surowy zestaw PHPMD nie flagował dostępu statycznego).
 * Używa jej silnik i bramki; wynik można też zbudować wprost przez `new GateResult(...)`.
 */
final class ResultFactory
{
    public function passed(?Checked $checked = null): GateResult
    {
        return new GateResult(GateStatus::Passed, null, $checked);
    }

    /**
     * @param list<Diagnostic> $diagnostics
     */
    public function failed(array $diagnostics, ?Checked $checked = null): GateResult
    {
        return new GateResult(GateStatus::Failed, null, $checked, $diagnostics);
    }

    public function skipped(ReasonCode $code, string $message): GateResult
    {
        return new GateResult(GateStatus::Skipped, new Reason($code, $message));
    }

    public function notScanned(ReasonCode $code, string $message, ?string $blockedBy = null): GateResult
    {
        return new GateResult(GateStatus::NotScanned, new Reason($code, $message, $blockedBy));
    }

    public function notImplemented(string $gate): GateResult
    {
        return $this->notScanned(ReasonCode::GateNotImplemented, sprintf('Bramka „%s” jest w kontrakcie konfiguracji, ale jej kod jeszcze nie istnieje.', $gate));
    }

    public function crashed(string $message): GateResult
    {
        return $this->notScanned(ReasonCode::GateCrashed, $message);
    }

    public function interrupted(string $message): GateResult
    {
        return $this->notScanned(ReasonCode::Interrupted, $message);
    }

    public function blocked(string $preconditionGate): GateResult
    {
        return $this->notScanned(
            ReasonCode::PreconditionFailed,
            sprintf('Bramka wstępna „%s” nie przeszła, kolejne bramki nie biegną.', $preconditionGate),
            $preconditionGate,
        );
    }

    public function nothingChecked(): GateResult
    {
        return $this->notScanned(ReasonCode::NothingChecked, 'Bramka zwróciła „przeszła”, ale nie sprawdziła żadnej jednostki.');
    }
}
