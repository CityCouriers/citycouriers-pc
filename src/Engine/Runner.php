<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Runner: wykonuje włączone bramki w kolejności rejestru, egzekwuje reguły fail-closed (docs/bramki.md)
 * i składa raport z kodem wyjścia. Wyjątek w bramce nie kończy biegu; błąd silnika i przerwanie tak.
 */
final readonly class Runner
{
    public function __construct(
        private GateRegistry $registry,
        private GateExecutor $executor,
        private ReportBuilder $reports = new ReportBuilder(),
    ) {}

    /**
     * @param list<string> $enabled nazwy bramek włączonych w `pc.json`
     */
    public function run(array $enabled, GateContext $context): RunReport
    {
        $state = new RunState();
        $outcomes = [];
        foreach ($this->registry->plan($enabled) as $name) {
            $outcomes[] = $this->execute($name, $context, $state);
        }
        $this->finalizeAll($outcomes, $context, $state);

        $entries = array_map(static fn(Outcome $outcome): GateEntry => $outcome->toEntry(), $outcomes);

        return $this->reports->fromRun($entries, $context, $this->executor->signal(), $state->engineError);
    }

    private function execute(string $name, GateContext $context, RunState $state): Outcome
    {
        $gate = $this->registry->get($name);
        $unrun = $this->executor->unrun($state);
        if ($unrun !== null) {
            return new Outcome($name, $gate, $unrun);
        }
        if ($gate === null) {
            return new Outcome($name, null, $this->executor->notImplemented($name));
        }

        $startedAt = hrtime(true);
        $outcome = new Outcome($name, $gate, $this->executor->main($gate, $context, $state), $startedAt);
        $outcome->stop();

        return $outcome;
    }

    /**
     * Faza `finalize` (reguła 7): raz dla każdej wywołanej bramki z tą fazą, w kolejności odwrotnej do rozpoczęcia.
     *
     * @param list<Outcome> $outcomes
     */
    private function finalizeAll(array $outcomes, GateContext $context, RunState $state): void
    {
        $cause = $this->executor->endCause($state);
        foreach (array_reverse($outcomes) as $outcome) {
            $gate = $outcome->gate;
            if (!$gate instanceof FinalizingGate || !$outcome->invoked() || $outcome->result->status === GateStatus::Skipped) {
                continue;
            }

            $outcome->result = $this->executor->finalize($gate, $context, $outcome->result, $state, $cause);
            $outcome->stop();
        }
    }
}
