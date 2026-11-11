<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Tryb bramki: blokująca wpływa na kod wyjścia, raportująca nigdy.
 */
enum GateMode: string
{
    case Blocking = 'blocking';
    case Reporting = 'reporting';
}
