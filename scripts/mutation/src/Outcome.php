<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Dev\Mutation;

/**
 * Wynik mutanta (wiersz `result` dziennika): status, kod wyjścia procesu mutanta (null: bez procesu albo timeout),
 * liczba wzorców filtra testów, tryb filtra (`testy`, `klasy`, `brak`), czas i linia podsumowania Pesta (`Tests: ...`).
 */
final readonly class Outcome
{
    public function __construct(
        public string $id,
        public Status $status,
        public ?int $exitCode,
        public int $patterns,
        public string $filter,
        public float $duration,
        public ?string $summary,
    ) {}
}
