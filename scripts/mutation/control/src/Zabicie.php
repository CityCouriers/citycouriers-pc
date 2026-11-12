<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Dev\MutationControl;

/**
 * Kontrola dodatnia mutacji: testy sprawdzają wynik tej metody, więc jej mutant musi zginąć.
 * Przeżycie oznacza, że w procesie mutanta testy nie biegną albo nie widzą zmutowanego kodu.
 */
final class Zabicie
{
    public static function suma(int $left, int $right): int
    {
        return $left + $right;
    }
}
