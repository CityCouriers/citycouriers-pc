<?php

declare(strict_types=1);

use CityCouriers\Pc\Dev\MutationControl\Przezycie;
use CityCouriers\Pc\Dev\MutationControl\Zabicie;

// Po dwa testy na klasę: filtr procesu mutanta ma wtedy dwa wzorce połączone `|`. Kontrola z jednym testem nie wykrywa
// rozcięcia filtra na `|` (tak było w #60: mutanty z jednym wzorcem liczyły się poprawnie, z dwoma i więcej fałszywie).

// Asercja tylko o typie wyniku: mutant `+` na `-` też zwraca int, więc żaden poprawny przebieg go nie zabije.
it('przeżycie: pierwszy test woła metodę bez sprawdzania wartości', function (): void {
    expect(Przezycie::suma(2, 3))->toBeInt();
});

it('przeżycie: drugi test woła metodę bez sprawdzania wartości', function (): void {
    expect(Przezycie::suma(7, 1))->toBeInt();
});

it('zabicie: pierwszy test sprawdza wynik', function (): void {
    expect(Zabicie::suma(2, 3))->toBe(5);
});

it('zabicie: drugi test sprawdza wynik', function (): void {
    expect(Zabicie::suma(7, 1))->toBe(8);
});
