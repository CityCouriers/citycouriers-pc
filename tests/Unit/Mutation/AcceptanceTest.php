<?php

declare(strict_types=1);

use CityCouriers\Pc\Dev\Mutation\Acceptance;
use CityCouriers\Pc\Dev\Mutation\Generated;
use CityCouriers\Pc\Dev\Mutation\Journal;
use CityCouriers\Pc\Dev\Mutation\Outcome;
use CityCouriers\Pc\Dev\Mutation\RunLog;
use CityCouriers\Pc\Dev\Mutation\Status;
use CityCouriers\Pc\Dev\Mutation\Target;
use CityCouriers\Pc\Dev\Mutation\Verdict;
use CityCouriers\Pc\Dev\Mutation\ZeroMutants;

/**
 * Spójny bieg: dwa cele z mutantami i jeden z wpisem „bez mutantów”, wszystkie mutanty zabite testem.
 *
 * @return array{targets: list<Target>, mutants: array<string, array{string, Status}>, zero: array<string, string>}
 */
function mutationRun(): array
{
    return [
        'targets' => [new Target('src/A.php', 'a', ['A']), new Target('src/B.php', 'b', ['B']), new Target('src/C.php', 'c', ['C'])],
        'mutants' => ['a1' => ['src/A.php', Status::Tested], 'a2' => ['src/A.php', Status::Tested], 'b1' => ['src/B.php', Status::Tested]],
        'zero' => ['src/C.php' => 'interfejs bez kodu wykonywalnego'],
    ];
}

/**
 * @param array<string, array{string, Status}> $mutants identyfikator → [plik, status]
 * @param list<string> $withoutResult identyfikatory bez wyniku (bieg przerwany)
 */
function mutationJournal(array $mutants, array $withoutResult = [], ?string $summary = '1 failed'): Journal
{
    $generated = [];
    $outcomes = [];
    foreach ($mutants as $id => [$file, $status]) {
        $generated[$id] = new Generated($id, $file, 'Pest\\Mutate\\Mutators\\Arithmetic\\PlusToMinus', 3, 3);
        if (!in_array($id, $withoutResult, true)) {
            $outcomes[$id] = new Outcome($id, $status, $status === Status::Tested ? 1 : 0, 2, 'testy', 0.2, $status === Status::Tested ? $summary : '2 passed');
        }
    }

    return new Journal($generated, $outcomes, []);
}

/**
 * Log zgodny z dziennikiem (te same liczby i lista żywych mutantów).
 *
 * @param array<string, array{string, Status}> $mutants
 */
function mutationLog(array $mutants): string
{
    $counts = [];
    $lines = [sprintf('  %d Mutations for %d Files created', count($mutants), count(array_unique(array_column($mutants, 0))))];
    foreach ($mutants as $id => [$file, $status]) {
        $counts[$status->label()] = ($counts[$status->label()] ?? 0) + 1;
        if ($status === Status::Untested || $status === Status::Uncovered) {
            $lines[] = sprintf('   %s  ./%s  > Line 3: PlusToMinus - ID: %s', strtoupper($status->value), $file, $id);
        }
    }
    $parts = [];
    foreach ($counts as $category => $count) {
        $parts[] = $count . ' ' . $category;
    }
    $lines[] = '  Mutations: ' . implode(', ', $parts === [] ? ['0 tested'] : $parts);

    return implode("\n", $lines);
}

/**
 * @param array<mixed> $override klucze: targets, mutants, zero, journal, log, exit, registry, controls, scope
 */
function mutationVerdict(array $override = []): Verdict
{
    $run = mutationRun();
    $mutants = $override['mutants'] ?? $run['mutants'];
    assert(is_array($mutants));
    /** @var array<string, array{string, Status}> $mutants */
    $journal = $override['journal'] ?? mutationJournal($mutants);
    $log = $override['log'] ?? mutationLog($mutants);
    $targets = $override['targets'] ?? $run['targets'];
    $zero = $override['zero'] ?? $run['zero'];
    $registry = $override['registry'] ?? [];
    $controls = $override['controls'] ?? [];
    assert($journal instanceof Journal && is_string($log) && is_array($targets) && is_array($zero) && is_array($registry) && is_array($controls));
    /** @var list<Target> $targets */
    /** @var array<string, string> $zero */
    /** @var list<string> $registry */
    /** @var list<string> $controls */
    $exit = array_key_exists('exit', $override) ? $override['exit'] : 0;
    assert(is_int($exit) || $exit === null);
    $scope = $override['scope'] ?? 'src';
    assert(is_string($scope));

    return Acceptance::evaluate($targets, $scope, $journal, RunLog::parse($log), $exit, new ZeroMutants($zero, []), $registry, $controls);
}

it('spójny, kompletny bieg bez żywych mutantów jest przyjęty, z licznikami łącznie i na cel', function (): void {
    $verdict = mutationVerdict();

    expect($verdict->reasons)->toBe([])
        ->and($verdict->accepted())->toBeTrue()
        ->and($verdict->notes)->toBe([])
        ->and($verdict->counts)->toBe(['tested' => 3])
        ->and($verdict->perTarget)->toBe([
            'src/A.php' => ['wygenerowane' => 2, 'tested' => 2],
            'src/B.php' => ['wygenerowane' => 1, 'tested' => 1],
            'src/C.php' => ['wygenerowane' => 0],
        ]);
});

it('żywy mutant z rejestru równoważnych nie odrzuca biegu', function (): void {
    $mutants = ['a1' => ['src/A.php', Status::Tested], 'a2' => ['src/A.php', Status::Untested], 'b1' => ['src/B.php', Status::Uncovered]];

    expect(mutationVerdict(['mutants' => $mutants, 'registry' => ['a2', 'b1']])->reasons)->toBe([]);
});

// Kontrole ujemne kompletności z #60: każda sytuacja odrzuca bieg z czytelnym powodem.
it('kontrola ujemna kompletności: bieg jest odrzucony z powodem', function (array $override, string $reason): void {
    $verdict = mutationVerdict($override);

    expect($verdict->accepted())->toBeFalse()
        ->and(implode("\n", $verdict->reasons))->toContain($reason);
})->with([
    'cel usunięty z manifestu' => [
        ['targets' => [new Target('src/A.php', 'a', ['A']), new Target('src/C.php', 'c', ['C'])]],
        'cel spoza manifestu: src/B.php (1 mutantów)',
    ],
    'pusty wynik (dziennik bez mutantów)' => [
        ['mutants' => [], 'zero' => []],
        'pusty wynik: dziennik nie zawiera żadnego wygenerowanego mutanta',
    ],
    'pusty wynik (brak logu)' => [
        ['log' => ''],
        'pusty wynik: log nie zawiera linii „N Mutations for M Files created”',
    ],
    'przerwany bieg (mutanty bez wyniku)' => [
        ['journal' => mutationJournal(mutationRun()['mutants'], ['b1'])],
        'bieg przerwany lub nieukończony: 1 mutantów bez wyniku (b1)',
    ],
    'przerwany bieg (brak podsumowania w logu)' => [
        ['log' => '  3 Mutations for 2 Files created', 'exit' => null],
        'bieg przerwany lub nieukończony: log nie zawiera podsumowania',
    ],
    'przerwany proces Pesta' => [
        ['exit' => null],
        'proces Pesta zakończył się kodem nieznanym: przerwany',
    ],
    'UNTESTED poza rejestrem' => [
        ['mutants' => ['a1' => ['src/A.php', Status::Untested], 'a2' => ['src/A.php', Status::Tested], 'b1' => ['src/B.php', Status::Tested]]],
        'UNTESTED poza rejestrem równoważnych: 1 mutantów (a1)',
    ],
    'UNCOVERED poza rejestrem' => [
        ['mutants' => ['a1' => ['src/A.php', Status::Uncovered], 'a2' => ['src/A.php', Status::Tested], 'b1' => ['src/B.php', Status::Tested]]],
        'UNCOVERED poza rejestrem równoważnych: 1 mutantów (a1)',
    ],
    'timeout' => [
        ['mutants' => ['a1' => ['src/A.php', Status::Timeout], 'a2' => ['src/A.php', Status::Tested], 'b1' => ['src/B.php', Status::Tested]]],
        'timeout (nigdy nie jest zabiciem): 1 mutantów (a1)',
    ],
    'mutant bez wyniku końcowego (pending)' => [
        ['mutants' => ['a1' => ['src/A.php', Status::None], 'a2' => ['src/A.php', Status::Tested], 'b1' => ['src/B.php', Status::Tested]]],
        'mutanty bez wyniku końcowego (pending): 1 (a1)',
    ],
    'niezgodna suma kategorii' => [
        ['log' => "  3 Mutations for 2 Files created\n  Mutations: 1 untested, 2 tested"],
        'niezgodna suma kategorii tested: log 2, dziennik 3',
    ],
    'niezgodna liczba wygenerowanych' => [
        ['log' => "  4 Mutations for 2 Files created\n  Mutations: 3 tested"],
        'niezgodna liczba wygenerowanych mutantów: log 4, dziennik 3',
    ],
    'niezgodna lista żywych mutantów' => [
        ['mutants' => ['a1' => ['src/A.php', Status::Untested], 'a2' => ['src/A.php', Status::Tested], 'b1' => ['src/B.php', Status::Tested]], 'registry' => ['a1'], 'log' => "  3 Mutations for 2 Files created\n  Mutations: 1 untested, 2 tested"],
        'niezgodna lista żywych mutantów: log wymienia 0, dziennik ma 1',
    ],
    'cel bez mutantów i bez wpisu' => [
        ['zero' => []],
        'cel bez mutantów i bez wpisu w bez-mutantow.json: src/C.php',
    ],
    'wpis „bez mutantów” dla celu z mutantami' => [
        ['zero' => ['src/C.php' => 'x', 'src/A.php' => 'y']],
        'nieaktualny wpis w bez-mutantow.json: src/A.php ma 2 mutantów',
    ],
    'wpis „bez mutantów” dla nieistniejącego celu' => [
        ['zero' => ['src/C.php' => 'x', 'src/D.php' => 'y']],
        'nieaktualny wpis w bez-mutantow.json: nie ma celu src/D.php',
    ],
    'proces Pesta z kodem niezerowym' => [
        ['exit' => 1],
        'proces Pesta zakończył się kodem 1 (oczekiwany 0)',
    ],
    'nieudana kontrola' => [
        ['controls' => ['kontrola zerowa: przykład']],
        'kontrola zerowa: przykład',
    ],
    // Obraz `--processes=N` bez łatki: każdy pokryty mutant „zabity” kodem 2, zanim Pest uruchomił test.
    'wszystkie zabicia bez uruchomionego testu' => [
        ['journal' => mutationJournal(mutationRun()['mutants'], [], null)],
        'wszystkie zabicia (3) bez uruchomionego testu (kod 1: 3)',
    ],
]);

it('wpis „bez mutantów” spoza kawałka nie odrzuca biegu w kawałku', function (): void {
    $verdict = mutationVerdict([
        'targets' => [new Target('src/A.php', 'a', ['A'])],
        'mutants' => ['a1' => ['src/A.php', Status::Tested]],
        'zero' => ['src/Engine/C.php' => 'x'],
        'scope' => 'src/A.php',
    ]);

    expect($verdict->reasons)->toBe([]);
});

it('uwagi: zabicie bez podsumowania testów i filtr zwinięty do klas nie odrzucają biegu, ale są widoczne', function (): void {
    $journal = mutationJournal(mutationRun()['mutants'], [], null);
    $outcomes = $journal->outcomes;
    $outcomes['b1'] = new Outcome('b1', Status::Tested, 255, 90, 'klasy', 1.0, '1 failed');
    $verdict = mutationVerdict(['journal' => new Journal($journal->generated, $outcomes, [])]);

    expect($verdict->accepted())->toBeTrue()
        ->and($verdict->notes)->toBe([
            'zabicia bez linii podsumowania testów (proces mutanta padł przed raportem, np. błąd krytyczny PHP): 2 (kod 1: 2)',
            'filtr testów w trybie „klasy” (za długi pełny filtr): 1 mutantów',
        ]);
});

// Obraz #73 w biegu szeregowym: mutant przeżywa wszystkie testy, a proces kończy się kodem 1 przez ostrzeżenia wrappera.
it('uwagi: zabicie bez nieudanego testu (same ostrzeżenia) jest widoczne z identyfikatorem', function (): void {
    $journal = mutationJournal(mutationRun()['mutants']);
    $outcomes = $journal->outcomes;
    $outcomes['a2'] = new Outcome('a2', Status::Tested, 1, 30, 'testy', 20.4, '6 warnings, 107 passed (419 assertions)');
    $verdict = mutationVerdict(['journal' => new Journal($journal->generated, $outcomes, [])]);

    expect($verdict->notes)->toBe(['zabicia bez nieudanego testu (podsumowanie bez „failed”, np. same ostrzeżenia, #73): 1 (a2)']);
});
