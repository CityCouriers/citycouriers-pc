<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Skutek reguły: `Narrow` uruchamia tylko wskazane bramki, `ForceFull` (zmiana samego mechanizmu bramek) pełny zestaw.
 */
enum RuleEffect: string
{
    case Narrow = 'narrow';
    case ForceFull = 'force_full';
}
