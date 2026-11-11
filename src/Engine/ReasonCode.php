<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Zamknięty zbiór kodów powodu (`reason.code`). Dodanie kodu to zmiana kontraktu.
 */
enum ReasonCode: string
{
    case NoMatchingFiles = 'no_matching_files';
    case DisabledInConfig = 'disabled_in_config';
    case ToolMissing = 'tool_missing';
    case ToolFailed = 'tool_failed';
    case GateCrashed = 'gate_crashed';
    case GateNotImplemented = 'gate_not_implemented';
    case MissingInput = 'missing_input';
    case NothingChecked = 'nothing_checked';
    case Timeout = 'timeout';
    case Interrupted = 'interrupted';
    case PreconditionFailed = 'precondition_failed';

    /**
     * Kod z tej grupy wolno łączyć tylko ze stanem `skipped` (decyzja zakresu), pozostałe tylko z `not_scanned`.
     */
    public function isScopeDecision(): bool
    {
        return $this === self::NoMatchingFiles || $this === self::DisabledInConfig;
    }
}
