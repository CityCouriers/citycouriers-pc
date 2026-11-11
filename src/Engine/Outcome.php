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

    public function __construct(
        public readonly string $name,
        public readonly ?Gate $gate,
        public GateResult $result,
        private readonly ?int $startedAt = null,
    ) {}

    public function invoked(): bool
    {
        return $this->startedAt !== null;
    }

    public function mode(): GateMode
    {
        return $this->gate?->mode() ?? GateMode::Blocking;
    }

    public function stop(): void
    {
        if ($this->startedAt !== null) {
            $this->elapsedMs = max(0, intdiv(hrtime(true) - $this->startedAt, 1_000_000));
        }
    }

    public function toEntry(): GateEntry
    {
        return new GateEntry($this->name, $this->mode(), $this->result, $this->elapsedMs);
    }
}
