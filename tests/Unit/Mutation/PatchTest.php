<?php

declare(strict_types=1);

use Pest\Mutate\MutationTest;

it('Pest ładuje lokalną kopię klasy wtyczki, nie oryginał z vendor', function (): void {
    $file = (string) (new ReflectionClass(MutationTest::class))->getFileName();

    expect(str_replace('\\', '/', $file))->toEndWith('scripts/mutation/patch/MutationTest.php');
});

it('strażnik dryfu: oryginał w vendor to ta sama wersja, z której zrobiono kopię', function (): void {
    // Czerwony po aktualizacji pest-plugin-mutate: przenieś zmiany z kopii na nowy oryginał i zaktualizuj skrót.
    $original = dirname(__DIR__, 3) . '/vendor/pestphp/pest-plugin-mutate/src/MutationTest.php';

    expect(hash_file('sha256', $original))->toBe(MutationTest::UPSTREAM_SHA256);
});

it('proces mutanta startuje od interpretera PHP, niezależnie od zapisu argv[0], bez opcji pokrycia i równoległości', function (): void {
    $script = dirname(__DIR__, 3) . '/vendor/bin/pest';
    // `--processes=N` w procesie mutanta: Pest bez --parallel kończy się kodem 2 („Unknown option”), a wtyczka liczy zabicie.
    $command = MutationTest::command(['vendor/bin/pest', '--mutate', '--coverage-php=x', '--parallel', '--processes=8', '--path=src']);

    expect($command[0])->toBe(PHP_BINARY)
        ->and(str_replace('\\', '/', $command[1]))->toBe(str_replace('\\', '/', (string) realpath($script)))
        ->and(array_slice($command, 2))->toBe(['--mutate', '--path=src'])
        ->and(MutationTest::command(['nie/ma/takiego/pliku']))->toBe([PHP_BINARY, 'nie/ma/takiego/pliku'])
        ->and(MutationTest::command([])[0])->toBe(PHP_BINARY)
        ->and(str_replace('\\', '/', MutationTest::command([])[1]))->toEndWith('vendor/bin/pest');
});

it('filtr testów: pełne wzorce, a gdy są za długie dla cmd.exe, nazwy klas testów; w ostateczności brak filtra', function (): void {
    $short = MutationTest::filterArgument(['ATest::(.*)a', 'BTest::(.*)b'], ['ATest::', 'BTest::']);
    $manyLong = array_map(static fn(int $i): string => 'ScopeTest::(.*)' . str_repeat('x', 100) . $i, range(1, 80));
    $collapsed = MutationTest::filterArgument($manyLong, ['ScopeTest::', 'OtherTest::']);
    $hopeless = MutationTest::filterArgument($manyLong, $manyLong);

    expect($short)->toBe(['--filter="ATest::(.*)a|BTest::(.*)b"', 'testy'])
        ->and($collapsed)->toBe(['--filter="ScopeTest::|OtherTest::"', 'klasy'])
        ->and($hopeless)->toBe([null, 'brak'])
        ->and(strlen('--filter="' . implode('|', $manyLong) . '"'))->toBeGreaterThan(MutationTest::MAX_FILTER_LENGTH);
});

it('podsumowanie Pesta z wyjścia procesu mutanta (z kolorami) albo null, gdy test nie wystartował', function (): void {
    expect(MutationTest::summary("\n  \e[90mTests:\e[39m    1 failed, 1 pending (1 assertions)  \n  Duration: 0.2s"))->toBe('1 failed, 1 pending (1 assertions)')
        ->and(MutationTest::summary("Brak dalszych plików.\n"))->toBeNull();
});
