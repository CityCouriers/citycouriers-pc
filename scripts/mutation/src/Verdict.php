<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Dev\Mutation;

/**
 * Werdykt biegu: przyjęty tylko bez żadnego powodu odrzucenia. Uwagi (`notes`) nie odrzucają biegu.
 */
final readonly class Verdict
{
    /**
     * @param list<string> $reasons powody odrzucenia
     * @param list<string> $notes
     * @param array<string, int> $counts kategoria → liczba mutantów (z dziennika)
     * @param array<string, array<string, int>> $perTarget cel → kategoria → liczba mutantów
     */
    public function __construct(
        public array $reasons,
        public array $notes,
        public array $counts,
        public array $perTarget,
    ) {}

    public function accepted(): bool
    {
        return $this->reasons === [];
    }
}
