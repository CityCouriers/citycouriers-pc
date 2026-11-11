<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Składa `RunCommand` z zależności. Domyślnie: bez przerwań, git przez proces w bieżącym katalogu i pusty rejestr
 * bramek (rośnie z przenoszeniem bramek, zob. docs/plan-przeniesienia.md).
 */
final readonly class RunCommandFactory
{
    public function __construct(
        private InterruptSignal $interrupt = new NeverInterrupted(),
        private ?Git $git = null,
        private GateRegistry $registry = new GateRegistry(),
        private ?string $root = null,
    ) {}

    public function create(): RunCommand
    {
        $root = $this->root ?? (getcwd() ?: '.');

        return new RunCommand(
            new Runner($this->registry, new GateExecutor($this->interrupt)),
            new RunPreparer(new ContextFactory($this->git ?? new ProcessGit($root), $root)),
        );
    }
}
