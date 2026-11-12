<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Dev\Mutation;

/**
 * Manifest biegu mutacji (#60): SHA bazowy, wersje i skróty konfiguracji, dokładne polecenie, cele ze skrótami i klasami,
 * liczby mutantów na cel, wynik każdego mutanta, liczniki z logu i werdykt z powodami. Pliki obok: run.log (kompletny
 * surowy log), journal.jsonl (dziennik), control.* i identity.log (kontrole).
 */
final class Manifest
{
    public const int VERSION = 1;

    /**
     * @param array<string, mixed> $environment
     * @param list<Target> $targets
     *
     * @return array<string, mixed>
     */
    public static function build(array $environment, string $command, string $scope, array $targets, Journal $journal, RunLog $log, Verdict $verdict): array
    {
        $mutants = [];
        foreach ($journal->generated as $id => $mutant) {
            $outcome = $journal->outcomes[$id] ?? null;
            $mutants[] = [
                'id' => $mutant->id,
                'plik' => $mutant->file,
                'linie' => $mutant->startLine === $mutant->endLine ? (string) $mutant->startLine : $mutant->startLine . '-' . $mutant->endLine,
                'mutator' => $mutant->mutatorName(),
                'status' => $outcome?->status->label() ?? 'bez wyniku',
                'kod' => $outcome?->exitCode,
                'wzorce' => $outcome?->patterns,
                'filtr' => $outcome?->filter,
                'testy' => $outcome?->summary,
            ];
        }
        usort($mutants, static fn(array $left, array $right): int => [$left['plik'], $left['linie'], $left['id']] <=> [$right['plik'], $right['linie'], $right['id']]);

        return [
            'wersja' => self::VERSION,
            'werdykt' => $verdict->accepted() ? 'przyjęty' : 'odrzucony',
            'powody' => $verdict->reasons,
            'uwagi' => $verdict->notes,
            'zakres' => $scope,
            'polecenie' => $command,
            'srodowisko' => $environment,
            'log' => ['wygenerowane' => $log->created, 'pliki' => $log->files, 'podsumowanie' => $log->summary],
            'liczniki' => $verdict->counts,
            'cele' => array_map(static fn(Target $target): array => [
                'plik' => $target->path,
                'sha256' => $target->sha256,
                'klasy' => $target->classes,
                'mutanty' => $verdict->perTarget[$target->path] ?? ['wygenerowane' => 0],
            ], $targets),
            'mutanty' => $mutants,
        ];
    }

    /**
     * Krótki raport na wyjście: werdykt, liczniki i powody.
     */
    public static function report(Verdict $verdict, string $out): string
    {
        $lines = [$verdict->accepted() ? 'BIEG PRZYJĘTY' : 'BIEG ODRZUCONY'];
        $counts = [];
        foreach ($verdict->counts as $category => $count) {
            $counts[] = $count . ' ' . $category;
        }
        $lines[] = 'Mutanty: ' . ($counts === [] ? 'brak' : implode(', ', $counts));
        foreach ($verdict->reasons as $reason) {
            $lines[] = '  - ' . $reason;
        }
        foreach ($verdict->notes as $note) {
            $lines[] = '  uwaga: ' . $note;
        }
        $lines[] = 'Manifest: ' . $out . '/manifest.json';

        return implode("\n", $lines);
    }
}
