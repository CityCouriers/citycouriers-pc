<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Jednostka, w której bramka liczy to, co sprawdziła (pole `checked.unit`).
 */
enum CheckedUnit: string
{
    case Files = 'files';
    case Tests = 'tests';
    case Checks = 'checks';
}
