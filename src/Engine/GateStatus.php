<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Stan wyniku bramki. Zbiór zamknięty (docs/bramki.md, „Stany wyniku bramki”).
 */
enum GateStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case NotScanned = 'not_scanned';

    /**
     * Porządek „gorszy wynik” dla fazy `finalize`: passed < not_scanned < failed. Stan `skipped` nie bierze w nim udziału.
     */
    public function severityRank(): int
    {
        return match ($this) {
            self::Passed => 0,
            self::NotScanned => 1,
            self::Failed => 2,
            self::Skipped => -1,
        };
    }
}
