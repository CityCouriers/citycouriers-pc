<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Dev\Mutation;

/**
 * Dziennik biegu mutacji (wiersze JSON pisane przez lokalną kopię `Pest\Mutate\MutationTest`, scripts/mutation/patch).
 * Każdy błąd formatu, duplikat identyfikatora i wynik bez wpisu o wygenerowaniu trafia do `errors`: dziennik, którego nie
 * da się w całości odczytać, nie jest dowodem.
 */
final readonly class Journal
{
    /**
     * @param array<string, Generated> $generated identyfikator → wygenerowany mutant
     * @param array<string, Outcome> $outcomes identyfikator → wynik
     * @param list<string> $errors
     */
    public function __construct(
        public array $generated,
        public array $outcomes,
        public array $errors,
    ) {}

    public static function parse(string $content, string $root): self
    {
        $generated = [];
        $outcomes = [];
        $errors = [];
        foreach (preg_split('/\R/', $content) ?: [] as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $entry = json_decode($line, true);
            $error = is_array($entry) ? self::add($entry, $root, $generated, $outcomes) : 'to nie jest obiekt JSON';
            if ($error !== null) {
                $errors[] = sprintf('dziennik, wiersz %d: %s', $index + 1, $error);
            }
        }
        foreach (array_keys(array_diff_key($outcomes, $generated)) as $id) {
            $errors[] = sprintf('dziennik: wynik mutanta %s bez wpisu o wygenerowaniu', $id);
        }

        return new self($generated, $outcomes, $errors);
    }

    /**
     * Ścieżka względna wobec katalogu repo z separatorami `/`; ścieżka spoza repo zostaje bezwzględna.
     */
    public static function relative(string $path, string $root): string
    {
        $normalized = str_replace('\\', '/', $path);
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';

        return strncasecmp($normalized, $prefix, strlen($prefix)) === 0 ? substr($normalized, strlen($prefix)) : $normalized;
    }

    /**
     * @param array<mixed> $entry
     * @param array<string, Generated> $generated
     * @param array<string, Outcome> $outcomes
     */
    private static function add(array $entry, string $root, array &$generated, array &$outcomes): ?string
    {
        $id = $entry['id'] ?? null;
        if (!is_string($id) || preg_match('/^[0-9a-f]+$/', $id) !== 1) {
            return 'brak poprawnego identyfikatora mutanta';
        }

        return match ($entry['event'] ?? null) {
            'generated' => self::addGenerated($id, $entry, $root, $generated),
            'result' => self::addOutcome($id, $entry, $outcomes),
            default => 'nieznane zdarzenie',
        };
    }

    /**
     * @param array<mixed> $entry
     * @param array<string, Generated> $generated
     */
    private static function addGenerated(string $id, array $entry, string $root, array &$generated): ?string
    {
        if (isset($generated[$id])) {
            return 'mutant ' . $id . ' wygenerowany dwa razy';
        }
        ['file' => $file, 'mutator' => $mutator, 'start_line' => $start, 'end_line' => $end] = $entry + ['file' => null, 'mutator' => null, 'start_line' => null, 'end_line' => null];
        if (!is_string($file) || $file === '' || !is_string($mutator) || $mutator === '' || !is_int($start) || !is_int($end)) {
            return 'niepełny wpis o wygenerowaniu mutanta ' . $id;
        }
        $generated[$id] = new Generated($id, self::relative($file, $root), $mutator, $start, $end);

        return null;
    }

    /**
     * @param array<mixed> $entry
     * @param array<string, Outcome> $outcomes
     */
    private static function addOutcome(string $id, array $entry, array &$outcomes): ?string
    {
        if (isset($outcomes[$id])) {
            return 'mutant ' . $id . ' ma dwa wyniki';
        }
        $status = is_string($entry['status'] ?? null) ? Status::tryFrom($entry['status']) : null;
        $exitCode = $entry['exit_code'] ?? null;
        $patterns = $entry['patterns'] ?? null;
        $filter = $entry['filter'] ?? null;
        $duration = $entry['duration'] ?? null;
        $summary = $entry['summary'] ?? null;
        if ($status === null || !(is_int($exitCode) || $exitCode === null) || !is_int($patterns) || !is_string($filter)
            || !(is_int($duration) || is_float($duration)) || !(is_string($summary) || $summary === null)) {
            return 'niepełny wynik mutanta ' . $id;
        }
        $outcomes[$id] = new Outcome($id, $status, $exitCode, $patterns, $filter, (float) $duration, $summary);

        return null;
    }
}
