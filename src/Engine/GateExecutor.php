<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

use Throwable;

/**
 * Wywołuje fazy bramki: główną i `finalize`. Wyjątek w bramce zamienia na `not_scanned` / `gate_crashed`,
 * niespójny wynik na błąd silnika (kod 5), a bramkom, które nie mogą ruszyć, wystawia wynik „nie przeskanowano”.
 */
final readonly class GateExecutor
{
    public function __construct(
        private InterruptSignal $interrupt,
        private ResultValidator $validator = new ResultValidator(),
        private ResultFactory $results = new ResultFactory(),
        private FinalizeCrash $crash = new FinalizeCrash(),
    ) {}

    public function signal(): ?int
    {
        return $this->interrupt->signal();
    }

    public function notImplemented(string $name): GateResult
    {
        return $this->results->notImplemented($name);
    }

    /**
     * Powody, dla których bramka w ogóle nie rusza: błąd silnika, przerwanie albo bramka wstępna (reguła 6).
     */
    public function unrun(RunState $state): ?GateResult
    {
        return match (true) {
            $state->engineError !== null => $this->results->interrupted('Bieg przerwany błędem silnika przed uruchomieniem bramki.'),
            $this->interrupt->signal() !== null => $this->results->interrupted('Bieg przerwany sygnałem przed uruchomieniem bramki.'),
            $state->blockedBy !== null => $this->results->blocked($state->blockedBy),
            default => null,
        };
    }

    /**
     * Faza główna. Wynik `passed` po przerwaniu w trakcie bramki jest niewiarygodny, więc staje się `not_scanned`.
     * Bramka wstępna, która nie przeszła, zatrzymuje kolejne bramki.
     */
    public function main(Gate $gate, GateContext $context, RunState $state): GateResult
    {
        $result = $this->invoke($gate, $context, $state);

        if ($result->status === GateStatus::Passed && $this->interrupt->signal() !== null) {
            $result = $this->results->interrupted('Bieg przerwany sygnałem w trakcie bramki, wynik nie jest wiarygodny.');
        }
        if ($gate->precondition() && ($result->status === GateStatus::Failed || $result->status === GateStatus::NotScanned)) {
            $state->blockedBy = $gate->name();
        }

        return $result;
    }

    /**
     * Faza `finalize` (reguła 7): wynik wolno tylko pogorszyć (passed < not_scanned < failed).
     */
    public function finalize(FinalizingGate $gate, GateContext $context, GateResult $before, RunState $state, EndCause $cause): GateResult
    {
        try {
            $after = $this->normalize($gate, $gate->finalize($context, $before, $cause), $state);
        } catch (Throwable $exception) {
            $text = sprintf('Wyjątek w fazie finalize bramki „%s”: %s. Przywrócenie stanu po bramce mogło się nie dokonać.', $gate->name(), $exception->getMessage());
            $state->engineError ??= $text;

            return $this->crash->worsen($gate, $before, $text);
        }

        if ($after->status->severityRank() < $before->status->severityRank()) {
            $state->engineError ??= sprintf('Faza finalize bramki „%s” poprawiła wynik (%s → %s), a wolno go tylko pogorszyć.', $gate->name(), $before->status->value, $after->status->value);

            return $before;
        }

        return $after;
    }

    private function invoke(Gate $gate, GateContext $context, RunState $state): GateResult
    {
        try {
            $result = $gate->run($context);
        } catch (Throwable $exception) {
            $state->gateException = true;

            return $this->results->crashed(sprintf('Wyjątek w bramce „%s”: %s', $gate->name(), $exception->getMessage()));
        }

        return $this->normalize($gate, $result, $state);
    }

    private function normalize(Gate $gate, GateResult $result, RunState $state): GateResult
    {
        try {
            return $this->validator->normalize($gate, $result);
        } catch (EngineException $exception) {
            $state->engineError ??= $exception->getMessage();

            return $this->results->crashed(sprintf('Niespójny wynik bramki „%s”: %s', $gate->name(), $exception->getMessage()));
        }
    }

    /**
     * Awaria `finalize` jest osobnym, widocznym faktem: `failed` zostaje `failed` (dochodzi diagnostyka),
     * `not_scanned` dostaje powód `gate_crashed`, a pozostałe stany stają się `not_scanned` / `gate_crashed`.
     */
    /**
     * Przyczyna końca fazy głównej, ustalana raz przed wszystkimi `finalize` (zdarzenia w trakcie `finalize` jej nie zmieniają).
     */
    public function endCause(RunState $state): EndCause
    {
        return match (true) {
            $state->engineError !== null => EndCause::EngineError,
            $this->interrupt->signal() !== null => EndCause::Interrupted,
            $state->gateException => EndCause::GateException,
            default => EndCause::Completed,
        };
    }
}
