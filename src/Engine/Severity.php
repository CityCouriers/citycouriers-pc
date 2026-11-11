<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
