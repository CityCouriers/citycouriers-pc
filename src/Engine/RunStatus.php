<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Stan najwyższego poziomu całego `pc run` (pole `status` w JSON). Kody wyjścia: docs/bramki.md.
 */
enum RunStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case UsageError = 'usage_error';
    case ConfigError = 'config_error';
    case Incomplete = 'incomplete';
    case Error = 'error';
    case Interrupted = 'interrupted';
}
