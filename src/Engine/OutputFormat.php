<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

enum OutputFormat: string
{
    case Text = 'text';
    case Json = 'json';
}
