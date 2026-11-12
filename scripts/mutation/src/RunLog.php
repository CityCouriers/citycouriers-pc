<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Dev\Mutation;

/**
 * Surowy log biegu Pest Mutate: linia „N Mutations for M Files created”, linia podsumowania `Mutations: ...` oraz bloki
 * UNTESTED/UNCOVERED z identyfikatorami. To drugie, niezależne od dziennika źródło: skrypt porównuje oba.
 * Licznik, którego nie udało się odczytać, jest nullem (błędem), nigdy zerem.
 */
final readonly class RunLog
{
    /**
     * @param array<string, int>|null $summary kategoria (`tested`, `untested`, `uncovered`, `pending`, `timeout`) → liczba
     * @param array<string, string> $listed identyfikator → kategoria z bloków UNTESTED/UNCOVERED
     */
    public function __construct(
        public ?int $created,
        public ?int $files,
        public ?array $summary,
        public array $listed,
    ) {}

    public static function parse(string $log): self
    {
        $plain = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $log) ?? $log;
        $created = null;
        $files = null;
        if (preg_match('/^\s*(\d+) Mutations? for (\d+) Files? created/m', $plain, $matches) === 1) {
            $created = (int) $matches[1];
            $files = (int) $matches[2];
        }

        return new self($created, $files, self::summary($plain), self::listed($plain));
    }

    /**
     * Liczba w danej kategorii podsumowania; brak kategorii w obecnym podsumowaniu to 0 (wtyczka pomija zera).
     */
    public function count(string $category): ?int
    {
        return $this->summary === null ? null : $this->summary[$category] ?? 0;
    }

    /**
     * @return array<string, int>|null
     */
    private static function summary(string $plain): ?array
    {
        if (preg_match('/^\s*Mutations:\s*(.*?)\s*$/m', $plain, $matches) !== 1) {
            return null;
        }
        $summary = [];
        foreach (explode(',', $matches[1]) as $part) {
            if (preg_match('/^\s*(\d+)\s+(tested|untested|uncovered|pending|timeout)\s*$/', $part, $item) !== 1) {
                return null;
            }
            $summary[$item[2]] = (int) $item[1];
        }

        return $summary;
    }

    /**
     * @return array<string, string>
     */
    private static function listed(string $plain): array
    {
        preg_match_all('/^\s*(UNTESTED|UNCOVERED)\s+.*?- ID: ([0-9a-f]+)\s*$/m', $plain, $matches, PREG_SET_ORDER);
        $listed = [];
        foreach ($matches as $match) {
            $listed[$match[2]] = strtolower($match[1]);
        }

        return $listed;
    }
}
