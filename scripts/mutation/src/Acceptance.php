<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Dev\Mutation;

/**
 * Zasady uznania biegu mutacji (#60, „Dowód kompletności biegu”). Naruszenie którejkolwiek odrzuca bieg; nie ma stanu
 * „zielony z uwagą”. Dziennik (z lokalnej kopii klasy wtyczki) i surowy log są dwoma niezależnymi źródłami: każda
 * niezgodność między nimi odrzuca bieg. Mutanty liczymy po identyfikatorze, więc żaden nie liczy się dwa razy.
 */
final class Acceptance
{
    private const int SAMPLE = 10;

    /**
     * @param list<Target> $targets cele z manifestu
     * @param list<string> $registry identyfikatory mutantów równoważnych z rejestru (ważne wpisy)
     * @param list<string> $controlFailures powody odrzucenia z kontroli (zerowej, dodatniej, tożsamościowej)
     */
    public static function evaluate(
        array $targets,
        string $scope,
        Journal $journal,
        RunLog $log,
        ?int $exitCode,
        ZeroMutants $zeroMutants,
        array $registry,
        array $controlFailures,
    ): Verdict {
        $reasons = [...$controlFailures, ...self::process($log, $exitCode), ...$journal->errors, ...$zeroMutants->errors];
        $reasons = [...$reasons, ...self::completeness($journal, $log), ...self::targets($targets, $scope, $journal, $zeroMutants)];
        $reasons = [...$reasons, ...self::results($journal, $registry), ...self::silentKills($journal)];

        return new Verdict($reasons, self::notes($journal), self::counts($journal), self::perTarget($targets, $journal));
    }

    /**
     * @return list<string>
     */
    private static function process(RunLog $log, ?int $exitCode): array
    {
        $reasons = [];
        if ($exitCode !== 0) {
            $reasons[] = sprintf('proces Pesta zakończył się kodem %s (oczekiwany 0)', $exitCode ?? 'nieznanym: przerwany');
        }
        if ($log->created === null) {
            $reasons[] = 'pusty wynik: log nie zawiera linii „N Mutations for M Files created”';
        }
        if ($log->summary === null) {
            $reasons[] = 'bieg przerwany lub nieukończony: log nie zawiera podsumowania „Mutations: ...”';
        }

        return $reasons;
    }

    /**
     * @return list<string>
     */
    private static function completeness(Journal $journal, RunLog $log): array
    {
        $reasons = [];
        if ($journal->generated === []) {
            $reasons[] = 'pusty wynik: dziennik nie zawiera żadnego wygenerowanego mutanta';
        }
        if ($log->created !== null && $log->created !== count($journal->generated)) {
            $reasons[] = sprintf('niezgodna liczba wygenerowanych mutantów: log %d, dziennik %d', $log->created, count($journal->generated));
        }
        $missing = array_keys(array_diff_key($journal->generated, $journal->outcomes));
        if ($missing !== []) {
            $reasons[] = sprintf('bieg przerwany lub nieukończony: %d mutantów bez wyniku (%s)', count($missing), self::sample($missing));
        }
        if ($log->summary !== null) {
            $counts = self::counts($journal);
            foreach (Status::cases() as $status) {
                $fromLog = $log->count($status->label()) ?? 0;
                if ($fromLog !== ($counts[$status->label()] ?? 0)) {
                    $reasons[] = sprintf('niezgodna suma kategorii %s: log %d, dziennik %d', $status->label(), $fromLog, $counts[$status->label()] ?? 0);
                }
            }
        }
        $alive = [];
        foreach ($journal->outcomes as $id => $outcome) {
            if ($outcome->status === Status::Untested || $outcome->status === Status::Uncovered) {
                $alive[$id] = $outcome->status->value;
            }
        }
        ksort($alive);
        $listed = $log->listed;
        ksort($listed);
        if ($log->summary !== null && $alive !== $listed) {
            $reasons[] = sprintf('niezgodna lista żywych mutantów: log wymienia %d, dziennik ma %d', count($listed), count($alive));
        }

        return $reasons;
    }

    /**
     * @param list<Target> $targets
     *
     * @return list<string>
     */
    private static function targets(array $targets, string $scope, Journal $journal, ZeroMutants $zeroMutants): array
    {
        $perFile = array_count_values(array_map(static fn(Generated $mutant): string => $mutant->file, array_values($journal->generated)));
        $known = array_flip(array_map(static fn(Target $target): string => $target->path, $targets));
        $reasons = [];
        foreach (array_diff_key($perFile, $known) as $file => $count) {
            $reasons[] = sprintf('cel spoza manifestu: %s (%d mutantów)', $file, $count);
        }
        foreach ($targets as $target) {
            $count = $perFile[$target->path] ?? 0;
            $listed = isset($zeroMutants->files[$target->path]);
            if ($count === 0 && !$listed) {
                $reasons[] = sprintf('cel bez mutantów i bez wpisu w bez-mutantow.json: %s', $target->path);
            }
            if ($count > 0 && $listed) {
                $reasons[] = sprintf('nieaktualny wpis w bez-mutantow.json: %s ma %d mutantów', $target->path, $count);
            }
        }
        $prefix = rtrim($scope, '/');
        foreach (array_keys($zeroMutants->files) as $path) {
            $inScope = $path === $prefix || str_starts_with($path, $prefix . '/');
            if ($inScope && !isset($known[$path])) {
                $reasons[] = sprintf('nieaktualny wpis w bez-mutantow.json: nie ma celu %s', $path);
            }
        }

        return $reasons;
    }

    /**
     * @param list<string> $registry
     *
     * @return list<string>
     */
    private static function results(Journal $journal, array $registry): array
    {
        $byStatus = [];
        foreach ($journal->outcomes as $id => $outcome) {
            $byStatus[$outcome->status->value][] = (string) $id;
        }
        $reasons = [];
        if (($byStatus['timeout'] ?? []) !== []) {
            $reasons[] = sprintf('timeout (nigdy nie jest zabiciem): %d mutantów (%s)', count($byStatus['timeout']), self::sample($byStatus['timeout']));
        }
        if (($byStatus['none'] ?? []) !== []) {
            $reasons[] = sprintf('mutanty bez wyniku końcowego (pending): %d (%s)', count($byStatus['none']), self::sample($byStatus['none']));
        }
        $accepted = array_flip($registry);
        foreach (['untested', 'uncovered'] as $category) {
            $alive = array_values(array_filter($byStatus[$category] ?? [], static fn(string $id): bool => !isset($accepted[$id])));
            if ($alive !== []) {
                $reasons[] = sprintf('%s poza rejestrem równoważnych: %d mutantów (%s)', strtoupper($category), count($alive), self::sample($alive));
            }
        }

        return $reasons;
    }

    /**
     * Wszystkie zabicia bez uruchomionego testu to obraz fałszywych zabić (proces mutanta pada na starcie, zanim Pest
     * cokolwiek uruchomi), a nie wynik mutacji. Pojedyncze takie zabicia są możliwe (np. błąd krytyczny PHP po zmianie
     * widoczności metody interfejsu), więc trafiają tylko do uwag.
     *
     * @return list<string>
     */
    private static function silentKills(Journal $journal): array
    {
        $tested = array_filter($journal->outcomes, static fn(Outcome $outcome): bool => $outcome->status === Status::Tested);
        $silent = self::silentKillCodes($journal);
        if ($tested === [] || array_sum($silent) !== count($tested)) {
            return [];
        }

        return [sprintf('wszystkie zabicia (%d) bez uruchomionego testu (%s): proces mutanta pada przed testami, wynik byłby fałszywy', count($tested), self::codes($silent))];
    }

    /**
     * @return array<string, int> kod wyjścia → liczba zabić bez linii podsumowania testów
     */
    private static function silentKillCodes(Journal $journal): array
    {
        $codes = [];
        foreach ($journal->outcomes as $outcome) {
            if ($outcome->status === Status::Tested && $outcome->summary === null) {
                $code = 'kod ' . ($outcome->exitCode ?? '?');
                $codes[$code] = ($codes[$code] ?? 0) + 1;
            }
        }
        ksort($codes);

        return $codes;
    }

    /**
     * Zabicie, w którym żaden test nie zawiódł (np. „6 warnings, 107 passed”), pochodzi z trybu `failOn*` konfiguracji,
     * a nie z asercji. Przy ostrzeżeniach z samego wrappera plików wtyczki (#73) to fałszywe zabicie, ukryty żywy mutant.
     *
     * @return list<string>
     */
    private static function killsWithoutFailure(Journal $journal): array
    {
        $ids = [];
        foreach ($journal->outcomes as $id => $outcome) {
            if ($outcome->status === Status::Tested && $outcome->summary !== null && !str_contains($outcome->summary, 'failed')) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, int> $codes
     */
    private static function codes(array $codes): string
    {
        $parts = [];
        foreach ($codes as $code => $count) {
            $parts[] = $code . ': ' . $count;
        }

        return implode(', ', $parts);
    }

    /**
     * @return list<string>
     */
    private static function notes(Journal $journal): array
    {
        $silent = self::silentKillCodes($journal);
        $filters = [];
        foreach ($journal->outcomes as $outcome) {
            if ($outcome->filter !== 'testy' && $outcome->status !== Status::Uncovered) {
                $filters[$outcome->filter] = ($filters[$outcome->filter] ?? 0) + 1;
            }
        }
        $notes = [];
        if ($silent !== []) {
            $notes[] = sprintf('zabicia bez linii podsumowania testów (proces mutanta padł przed raportem, np. błąd krytyczny PHP): %d (%s)', array_sum($silent), self::codes($silent));
        }
        $withoutFailure = self::killsWithoutFailure($journal);
        if ($withoutFailure !== []) {
            $notes[] = sprintf('zabicia bez nieudanego testu (podsumowanie bez „failed”, np. same ostrzeżenia, #73): %d (%s)', count($withoutFailure), self::sample($withoutFailure));
        }
        foreach ($filters as $mode => $count) {
            $notes[] = sprintf('filtr testów w trybie „%s” (za długi pełny filtr): %d mutantów', $mode, $count);
        }

        return $notes;
    }

    /**
     * @return array<string, int>
     */
    private static function counts(Journal $journal): array
    {
        $counts = [];
        foreach ($journal->outcomes as $outcome) {
            $counts[$outcome->status->label()] = ($counts[$outcome->status->label()] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param list<Target> $targets
     *
     * @return array<string, array<string, int>>
     */
    private static function perTarget(array $targets, Journal $journal): array
    {
        $perTarget = [];
        foreach ($targets as $target) {
            $perTarget[$target->path] = ['wygenerowane' => 0];
        }
        foreach ($journal->generated as $id => $mutant) {
            $label = isset($journal->outcomes[$id]) ? $journal->outcomes[$id]->status->label() : 'bez wyniku';
            $perTarget[$mutant->file]['wygenerowane'] = ($perTarget[$mutant->file]['wygenerowane'] ?? 0) + 1;
            $perTarget[$mutant->file][$label] = ($perTarget[$mutant->file][$label] ?? 0) + 1;
        }
        ksort($perTarget);

        return $perTarget;
    }

    /**
     * @param list<string> $ids
     */
    private static function sample(array $ids): string
    {
        sort($ids);
        $shown = array_slice($ids, 0, self::SAMPLE);

        return implode(', ', $shown) . (count($ids) > self::SAMPLE ? ', ...' : '');
    }
}
