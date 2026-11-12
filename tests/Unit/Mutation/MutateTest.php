<?php

declare(strict_types=1);

use CityCouriers\Pc\Dev\Mutation\Generated;
use CityCouriers\Pc\Dev\Mutation\Journal;
use CityCouriers\Pc\Dev\Mutation\Manifest;
use CityCouriers\Pc\Dev\Mutation\Mutate;
use CityCouriers\Pc\Dev\Mutation\Outcome;
use CityCouriers\Pc\Dev\Mutation\Redactor;
use CityCouriers\Pc\Dev\Mutation\RunLog;
use CityCouriers\Pc\Dev\Mutation\Status;
use CityCouriers\Pc\Dev\Mutation\Target;
use CityCouriers\Pc\Dev\Mutation\Verdict;
use CityCouriers\Pc\Dev\Mutation\ZeroMutants;

it('opcje: domyślnie pełny bieg równoległy na src do build/mutation', function (): void {
    expect(Mutate::options([]))->toBe(['path' => 'src', 'parallel' => true, 'processes' => Mutate::PROCESSES, 'out' => 'build/mutation', 'help' => false])
        ->and(Mutate::options(['--serial', '--path=src\\Scope\\', '--out=build/m', '--processes=4', '-h']))
        ->toBe(['path' => 'src/Scope', 'parallel' => false, 'processes' => 4, 'out' => 'build/m', 'help' => true]);
});

it('kontrola ujemna: nieznany argument i ścieżka spoza repo to błąd użycia', function (string $argument, string $message): void {
    Mutate::options([$argument]);
})->throws(RuntimeException::class)->with([
    'nieznany argument' => ['--covered-only', 'Nieznany argument'],
    'zero procesów' => ['--processes=0', 'Nieznany argument'],
    'wyjście poza repo' => ['--out=../poza', 'względna'],
    'ścieżka bezwzględna' => ['--path=/etc', 'względna'],
    'litera dysku' => ['--path=C:/src', 'względna'],
]);

it('main: pomoc kończy się kodem 0, błąd użycia kodem 2, bez uruchamiania Pesta', function (): void {
    $mutate = new Mutate(dirname(__DIR__, 3), false);

    expect($mutate->main(['--help']))->toBe(0)
        ->and($mutate->main(['--nie-ma']))->toBe(2);
});

it('polecenie Pest Mutate: PHP, skrypt Pesta, stałe opcje wtyczki, cel i równoległość (te same dla kontroli i biegu)', function (): void {
    $command = Mutate::command('/repo', ['-d', 'pcov.enabled=1'], ['--path=src'], ['--parallel', '--processes=8']);

    expect($command)->toBe([PHP_BINARY, '-d', 'memory_limit=2G', '-d', 'pcov.enabled=1', '/repo/vendor/bin/pest', '--mutate', '--everything', '--path=src', '--no-cache', '--parallel', '--processes=8']);
});

it('stała lista mutatorów to pięć nazw z #60', function (): void {
    expect(Mutate::MUTATORS)->toBe(['SetDefault', 'SetLaravel', 'SetVisibility', 'EqualityIdenticalToEqual', 'EqualityNotIdenticalToNotEqual']);
});

it('lista „bez mutantów”: wpisy z uzasadnieniem, a uszkodzony plik albo pusty powód to błąd', function (): void {
    $valid = ZeroMutants::parse('{"version": 1, "pliki": {"src/I.php": "interfejs bez kodu wykonywalnego"}}');

    expect($valid->files)->toBe(['src/I.php' => 'interfejs bez kodu wykonywalnego'])
        ->and($valid->errors)->toBe([])
        ->and(ZeroMutants::parse('')->errors)->toBe(['bez-mutantow.json: oczekiwany obiekt z "version": 1 i obiektem "pliki"'])
        ->and(ZeroMutants::parse('{"version": 2, "pliki": {}}')->errors)->toHaveCount(1)
        ->and(ZeroMutants::parse('{"version": 1, "pliki": {"src/I.php": " "}}')->errors)->toBe(['bez-mutantow.json: wpis "src/I.php" bez uzasadnienia']);
});

it('lista „bez mutantów” w repo jest poprawna', function (): void {
    $file = dirname(__DIR__, 3) . '/scripts/mutation/bez-mutantow.json';

    expect(ZeroMutants::parse((string) file_get_contents($file))->errors)->toBe([]);
});

it('redakcja usuwa ścieżki z maszyny w każdym zapisie (ukośniki, odwrócone, JSON, inna wielkość liter)', function (): void {
    $redactor = new Redactor('D:\\Praca\\repo', 'C:\\php\\8.4\\php.exe', 'C:\\Users\\ktos');
    $text = 'D:\\Praca\\repo\\src\\A.php d:/praca/repo/src/B.php "D:\\\\Praca\\\\repo\\\\src" C:\\php\\8.4\\php.exe C:\\Users\\ktos\\AppData';

    expect($redactor->redact($text))->toBe('.\\src\\A.php ./src/B.php ".\\\\src" <php>\\php.exe ~\\AppData');
});

it('redakcja ścieżek z Linuksa: katalog domowy, interpreter i repo w nim, kropki w tekście zostają', function (): void {
    $redactor = new Redactor('/srv/praca/repo', '/usr/bin/php8.4', '/srv/praca');
    $text = '/srv/praca/repo/src/A.php /usr/bin/php8.4 /srv/praca/.config wersja 1.2.3';

    expect($redactor->redact($text))->toBe('./src/A.php <php>/php8.4 ~/.config wersja 1.2.3');
});

// Kontrola ujemna: ścieżka bez separatora (interpreter z PATH, katalog „.”) nie może zamieniać zwykłego tekstu.
it('redakcja pomija ścieżki bez separatora', function (string $phpBinary): void {
    $redactor = new Redactor('/praca/repo', $phpBinary, '');

    expect($redactor->redact('src/A.php wersja 1.2.3 php.exe'))->toBe('src/A.php wersja 1.2.3 php.exe');
})->with(['nazwa z PATH' => 'php', 'katalog bieżący' => './php', 'katalog główny' => '/php']);

it('manifest: werdykt, polecenie, cele z licznikami i każdy mutant posortowany po pliku i linii', function (): void {
    $journal = new Journal(
        [
            'b2' => new Generated('b2', 'src/B.php', 'Pest\\Mutate\\Mutators\\Arithmetic\\PlusToMinus', 9, 11),
            'a1' => new Generated('a1', 'src/A.php', 'Pest\\Mutate\\Mutators\\Equality\\IdenticalToEqual', 3, 3),
        ],
        ['a1' => new Outcome('a1', Status::Untested, 0, 2, 'testy', 0.1, '2 passed')],
        [],
    );
    $verdict = new Verdict(['powód'], ['uwaga'], ['untested' => 1], ['src/A.php' => ['wygenerowane' => 1, 'untested' => 1]]);
    $manifest = Manifest::build(['sha' => 'abc'], 'php pest --mutate', 'src', [new Target('src/A.php', 'h', ['A'])], $journal, RunLog::parse('  2 Mutations for 2 Files created'), $verdict);

    expect($manifest['werdykt'])->toBe('odrzucony')
        ->and($manifest['powody'])->toBe(['powód'])
        ->and($manifest['uwagi'])->toBe(['uwaga'])
        ->and($manifest['polecenie'])->toBe('php pest --mutate')
        ->and($manifest['log'])->toBe(['wygenerowane' => 2, 'pliki' => 2, 'podsumowanie' => null])
        ->and($manifest['cele'])->toBe([['plik' => 'src/A.php', 'sha256' => 'h', 'klasy' => ['A'], 'mutanty' => ['wygenerowane' => 1, 'untested' => 1]]])
        ->and($manifest['mutanty'])->toBe([
            ['id' => 'a1', 'plik' => 'src/A.php', 'linie' => '3', 'mutator' => 'IdenticalToEqual', 'status' => 'untested', 'kod' => 0, 'wzorce' => 2, 'filtr' => 'testy', 'testy' => '2 passed'],
            ['id' => 'b2', 'plik' => 'src/B.php', 'linie' => '9-11', 'mutator' => 'PlusToMinus', 'status' => 'bez wyniku', 'kod' => null, 'wzorce' => null, 'filtr' => null, 'testy' => null],
        ])
        ->and(Manifest::report($verdict, 'build/mutation'))->toBe("BIEG ODRZUCONY\nMutanty: 1 untested\n  - powód\n  uwaga: uwaga\nManifest: build/mutation/manifest.json");
});
