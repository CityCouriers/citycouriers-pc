<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Tests\Support;

use Closure;
use CityCouriers\Pc\Engine\CheckedUnit;
use CityCouriers\Pc\Engine\EndCause;
use CityCouriers\Pc\Engine\FinalizingGate;
use CityCouriers\Pc\Engine\GateContext;
use CityCouriers\Pc\Engine\GateMode;
use CityCouriers\Pc\Engine\GateResult;
use CityCouriers\Pc\Engine\GateTriggers;
use CityCouriers\Pc\Engine\Checked;
use CityCouriers\Pc\Engine\Diagnostic;
use CityCouriers\Pc\Engine\ReasonCode;
use CityCouriers\Pc\Engine\ResultFactory;

/**
 * Atrapa bramki do testów silnika. Znacznik uruchomienia trafia do wspólnego dziennika zdarzeń.
 * Opcjonalna faza `finalize` (reguła 7) włącza się przez `withFinalize()`.
 */
final class FakeGate implements FinalizingGate
{
    /** @var Closure(GateContext): GateResult */
    private Closure $run;

    /** @var (Closure(GateContext, GateResult, EndCause): GateResult)|null */
    private ?Closure $finalize = null;

    private GateTriggers $triggers;

    public function __construct(
        private readonly string $name,
        private readonly GateMode $mode = GateMode::Blocking,
        private readonly bool $precondition = false,
        private readonly ?CheckedUnit $unit = CheckedUnit::Checks,
        private readonly EventLog $log = new EventLog(),
    ) {
        $this->triggers = new GateTriggers();
        $this->run = fn(): GateResult => (new ResultFactory())->passed(new Checked(CheckedUnit::Checks, 1));
    }

    /**
     * @param Closure(GateContext): GateResult $run
     */
    public function does(Closure $run): self
    {
        $this->run = $run;

        return $this;
    }

    public function passes(): self
    {
        return $this->does(fn(): GateResult => (new ResultFactory())->passed($this->unit === null ? null : new Checked($this->unit, 3)));
    }

    public function fails(string $message = 'celowy błąd', ?string $file = 'src/Zly.php'): self
    {
        return $this->does(fn(): GateResult => (new ResultFactory())->failed([new Diagnostic($message, 'regula', $file, 3, 1)], $this->unit === null ? null : new Checked($this->unit, 1)));
    }

    public function missingTool(): self
    {
        return $this->does(fn(): GateResult => (new ResultFactory())->notScanned(ReasonCode::ToolMissing, 'Brak narzędzia. Zainstaluj: composer require --dev vendor/narzedzie'));
    }

    /**
     * @param Closure(GateContext, GateResult, EndCause): GateResult $finalize
     */
    public function withFinalize(Closure $finalize): self
    {
        $this->finalize = $finalize;

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function mode(): GateMode
    {
        return $this->mode;
    }

    public function precondition(): bool
    {
        return $this->precondition;
    }

    public function checkedUnit(): ?CheckedUnit
    {
        return $this->unit;
    }

    public function withTriggers(GateTriggers $triggers): self
    {
        $this->triggers = $triggers;

        return $this;
    }

    public function triggers(): GateTriggers
    {
        return $this->triggers;
    }

    public function run(GateContext $context): GateResult
    {
        $this->log->add('run:' . $this->name);

        return ($this->run)($context);
    }

    public function finalize(GateContext $context, GateResult $result, EndCause $cause): GateResult
    {
        $this->log->add('finalize:' . $this->name . ':' . $cause->value);

        return $this->finalize === null ? $result : ($this->finalize)($context, $result, $cause);
    }
}
