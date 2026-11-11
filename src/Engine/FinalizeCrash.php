<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Wynik bramki po wyjątku w jej fazie `finalize` (reguła 7). Awaria jest osobnym, widocznym faktem:
 * `failed` zostaje `failed` (dochodzi diagnostyka `finalize_crashed`), `not_scanned` dostaje powód `gate_crashed`
 * z wcześniejszym powodem w komunikacie, pozostałe stany stają się `not_scanned` / `gate_crashed`.
 */
final readonly class FinalizeCrash
{
    public function __construct(
        private ResultValidator $validator = new ResultValidator(),
        private ResultFactory $results = new ResultFactory(),
    ) {}

    public function worsen(Gate $gate, GateResult $before, string $text): GateResult
    {
        return match ($before->status) {
            GateStatus::Failed => $this->validator->normalize($gate, new GateResult(
                GateStatus::Failed,
                null,
                $before->checked,
                [...$before->diagnostics, new Diagnostic($text, 'finalize_crashed')],
            )),
            GateStatus::NotScanned => new GateResult(
                GateStatus::NotScanned,
                new Reason(ReasonCode::GateCrashed, sprintf('%s Wcześniejszy powód: %s', $text, $before->reason?->message)),
                $before->checked,
            ),
            default => $this->results->crashed($text),
        };
    }
}
