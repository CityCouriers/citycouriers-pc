<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Wynik całego `pc run`: jedno źródło dla terminala, JSON i kodu wyjścia procesu.
 */
final readonly class RunReport
{
    /**
     * @param list<GateEntry> $entries
     */
    public function __construct(
        public RunStatus $status,
        public int $exitCode,
        public bool $complete,
        public ?RunScope $scope,
        public ?string $diffBase,
        public array $entries,
        public ?RunError $error,
    ) {}

    /**
     * Liczniki zgodne z listą `entries`.
     *
     * @return array{gates: int, passed: int, failed: int, skipped: int, not_scanned: int, blocking_failed: int, reporting_failed: int}
     */
    public function summary(): array
    {
        $count = fn(GateStatus $status, ?GateMode $mode = null): int => count(array_filter(
            $this->entries,
            static fn(GateEntry $entry): bool => $entry->result->status === $status && ($mode === null || $entry->mode === $mode),
        ));

        return [
            'gates' => count($this->entries),
            'passed' => $count(GateStatus::Passed),
            'failed' => $count(GateStatus::Failed),
            'skipped' => $count(GateStatus::Skipped),
            'not_scanned' => $count(GateStatus::NotScanned),
            'blocking_failed' => $count(GateStatus::Failed, GateMode::Blocking),
            'reporting_failed' => $count(GateStatus::Failed, GateMode::Reporting),
        ];
    }
}
