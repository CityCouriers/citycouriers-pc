<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

enum RunScope: string
{
    case Full = 'full';
    case Staged = 'staged';
}
