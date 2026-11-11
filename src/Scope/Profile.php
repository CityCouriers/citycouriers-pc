<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Profil egzekwowania zakresu (polityka, #40). Zamknięta lista: `local-narrow` (hook) wolno pominąć bramki bez plików
 * wyzwalających, `enforce-all` (CI) uruchamia pełny zestaw bez względu na wynik klasyfikatora. Brak albo nieznana wartość
 * to zawsze `enforce-all` (fail-closed), zob. `ProfileResolver`.
 */
enum Profile: string
{
    case LocalNarrow = 'local-narrow';
    case EnforceAll = 'enforce-all';
}
