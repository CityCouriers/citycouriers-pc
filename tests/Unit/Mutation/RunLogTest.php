<?php

declare(strict_types=1);

use CityCouriers\Pc\Dev\Mutation\RunLog;

/**
 * Fragment logu Pest Mutate 5.0.2 z biegu równoległego (z kodami kolorów, jak w pliku przekierowanym z terminala).
 */
function mutationParallelLog(): string
{
    return implode("\n", [
        '  Tests:    376 passed (1309 assertions)',
        "\e[1A  Parallel: 32 processes",
        '  Mutating application files...',
        "  \e[90m2001 Mutations for 74 Files created\e[39m",
        '  ----x..tt',
        "   \e[39;41;1m UNTESTED \e[39;49;22m \e[39;1m./src/Scope/SelectionRenderer.php \e[39;22m\e[90m > Line 26: ConcatRemoveLeft - ID: f07273c0557b0fe6\e[39m",
        '   UNCOVERED  ./src/Version.php  > Line 12: ConstantPublicToProtected - ID: 270b75c7d44caad8',
        "  \e[90mMutations:\e[39m \e[31;1m27 untested\e[39;22m\e[90m,\e[39m 296 uncovered, 2 timeout, 1676 tested",
        '  Score:     83.86%',
    ]);
}

it('czyta liczbę wygenerowanych, podsumowanie i identyfikatory żywych mutantów (z kodami kolorów)', function (): void {
    $log = RunLog::parse(mutationParallelLog());

    expect($log->created)->toBe(2001)
        ->and($log->files)->toBe(74)
        ->and($log->summary)->toBe(['untested' => 27, 'uncovered' => 296, 'timeout' => 2, 'tested' => 1676])
        ->and($log->listed)->toBe(['f07273c0557b0fe6' => 'untested', '270b75c7d44caad8' => 'uncovered'])
        ->and($log->count('pending'))->toBe(0)
        ->and($log->count('tested'))->toBe(1676);
});

it('bieg bez mutantów: zero wygenerowanych to liczba odczytana, nie brak', function (): void {
    $log = RunLog::parse("  0 Mutations for 0 Files created\n  Mutations: 0 tested\n  INFO  No mutations created.");

    expect($log->created)->toBe(0)
        ->and($log->files)->toBe(0)
        ->and($log->summary)->toBe(['tested' => 0]);
});

it('kontrola ujemna: licznik, którego nie da się odczytać, jest nullem, nigdy zerem', function (string $log): void {
    $parsed = RunLog::parse($log);

    expect($parsed->summary)->toBeNull()
        ->and($parsed->count('tested'))->toBeNull();
})->with([
    'pusty log' => [''],
    'bieg przerwany przed podsumowaniem' => ["  2001 Mutations for 74 Files created\n  ....x"],
    'nieznana kategoria w podsumowaniu' => ['  Mutations: 3 tested, 2 escaped'],
]);

it('log bez linii o wygenerowaniu nie ma liczby mutantów', function (): void {
    expect(RunLog::parse('  Tests:    376 passed')->created)->toBeNull();
});
