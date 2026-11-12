<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Dlaczego klasyfikator zwrócił pełny zestaw bramek. `None` oznacza zakres z reguł (zawężony).
 */
enum FullCause: string
{
    case None = 'none';
    case ForcedAll = 'forced_all';
    case EmptyList = 'empty_list';
    case SpecialMode = 'special_mode';
    case OddPath = 'odd_path';
    case NoRule = 'no_rule';
    case Mechanism = 'mechanism';
    case Unresolved = 'unresolved';
}
