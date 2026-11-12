<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Dev\MutationControl;

/**
 * Kontrola zerowa mutacji: testy wołają tę metodę, ale sprawdzają tylko typ wyniku, więc jej mutant musi przeżyć.
 * Zabity mutant tej klasy oznacza fałszywe zabicie (proces mutanta padł bez uruchomienia testu).
 */
final class Przezycie
{
    public static function suma(int $left, int $right): int
    {
        return $left + $right;
    }
}
