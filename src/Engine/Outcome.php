<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Stan jednej bramki w trakcie biegu (zmienny, wewnętrzny dla `Runner`). `gate` jest `null`, gdy bramka
 * nie ma kodu. Bramka ma `startedAt`, gdy wywołano jej fazę główną (tylko wtedy dostaje `finalize`).
 */
final class Outcome
{
    private ?int $elapsedMs = null;

    private ?int $mark;

    public function __construct(
        public readonly string $name,
        public readonly ?Gate $gate,
        public GateResult $result,
        private readonly ?int $startedAt = null,
    ) {
        $this->mark = $startedAt;
    }

    public function invoked(): bool
    {
        return $this->startedAt !== null;
    }

    public function mode(): GateMode
    {
        return $this->gate?->mode() ?? GateMode::Blocking;
    }

    /**
     * Dolicza czas od ostatniego startu (fazy głównej albo `finalize`), bez czasu innych bramek między fazami.
     */
    public function stop(): void
    {
        if ($this->mark !== null) {
            $this->elapsedMs = ($this->elapsedMs ?? 0) + max(0, intdiv(hrtime(true) - $this->mark, 1_000_000));
            $this->mark = null;
        }
    }

    public function resume(): void
    {
        if ($this->startedAt !== null) {
            $this->mark = hrtime(true);
        }
    }

    public function toEntry(): GateEntry
    {
        return new GateEntry($this->name, $this->mode(), $this->result, $this->elapsedMs);
    }
}
