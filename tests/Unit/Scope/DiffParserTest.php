<?php

declare(strict_types=1);

use CityCouriers\Pc\Tests\Support\ScopeSupport;
use CityCouriers\Pc\Scope\CommandLineSource;
use CityCouriers\Pc\Scope\CommitMode;
use CityCouriers\Pc\Scope\CommitModeDetector;
use CityCouriers\Pc\Scope\DiffParser;
use CityCouriers\Pc\Scope\ScopeException;
use CityCouriers\Pc\Tests\Support\RawDiff;

it('rozbiór diffu: ścieżki dodane, zmienione i usunięte', function (): void {
    $parsed = (new DiffParser())->parse(RawDiff::entry('000000', '100644', 'A', 'a.md') . RawDiff::entry('100644', '000000', 'D', 'b.md') . RawDiff::entry('100644', '100755', 'M', 'c.sh'));

    expect($parsed->paths)->toBe(['a.md', 'b.md', 'c.sh'])->and($parsed->specialModes)->toBeFalse();
});

it('pusty diff to pusta lista, nie błąd (decyzję o niej podejmuje klasyfikator)', function (): void {
    expect((new DiffParser())->parse('')->paths)->toBe([]);
});

it('tryby 120000 i 160000 po dowolnej stronie to dowiązanie albo submoduł', function (string $old, string $new): void {
    expect((new DiffParser())->parse(RawDiff::entry($old, $new, 'M', 'x'))->specialModes)->toBeTrue();
})->with([['100644', '120000'], ['120000', '100644'], ['160000', '160000'], ['000000', '160000']]);

it('niezaufany diff: każde odstępstwo od formatu to ScopeException (pełny zestaw bramek)', function (string $output, string $message): void {
    expect(fn() => (new DiffParser())->parse($output))->toThrow(ScopeException::class, $message);
})->with([
    'ucięte wyjście bez końcowego NUL' => [substr(RawDiff::entry('000000', '100644', 'A', 'a.md'), 0, -1), 'ucięte'],
    'ucięte w połowie wpisu' => [substr(RawDiff::entry('000000', '100644', 'A', 'a.md'), 0, 20), 'ucięte'],
    'brak ścieżki po metadanych' => [":000000 100644 " . str_repeat('a', 40) . ' ' . str_repeat('b', 40) . " A\0", 'liczba tokenów'],
    'token spoza formatu' => ["śmieci\0plik\0", 'spoza formatu'],
    'ścieżka w miejscu metadanych' => ["plik\0:000000 100644 aaaa bbbb A\0", 'spoza formatu'],
    'status U (konflikt)' => [RawDiff::entry('100644', '100644', 'U', 'x'), 'status U'],
    'status T (zmiana typu)' => [RawDiff::entry('100644', '120000', 'T', 'x'), 'status T'],
    'status X' => [RawDiff::entry('100644', '100644', 'X', 'x'), 'status X'],
    'status R mimo --no-renames' => [RawDiff::entry('100644', '100644', 'R', 'x'), 'status R'],
    'status z numerem podobieństwa' => [':100644 100644 ' . str_repeat('a', 40) . ' ' . str_repeat('b', 40) . " R100\0x\0", 'spoza formatu'],
]);

/**
 * @param list<string>|null $argv
 */
function scopeMode(?array $argv): CommitMode
{
    return (new CommitModeDetector())->detect($argv);
}

it('tryb commita: zwykły commit tylko po dodatnim potwierdzeniu z zamkniętej allowlisty opcji', function (array $argv): void {
    expect(scopeMode(ScopeSupport::strings($argv)))->toBe(CommitMode::Plain);
})->with([
    'git commit' => [['git', 'commit']],
    '-m z wartością' => [['git', 'commit', '-m', 'docs']],
    '-m z wartością zaczynającą się od myślnika' => [['git', 'commit', '-m', '--amend']],
    '-am' => [['git', 'commit', '-am', 'docs']],
    '-mTekst' => [['git', 'commit', '-mdocs']],
    '--message=x' => [['git', 'commit', '--message=docs']],
    '--message x' => [['git', 'commit', '--message', 'docs']],
    '--allow-empty' => [['git', 'commit', '--allow-empty', '-m', 'x']],
    'pełna ścieżka git.exe' => [['C:\\Program Files\\Git\\mingw64\\bin\\git.exe', 'commit', '-q']],
    'opcje globalne -c i -C' => [['/usr/bin/git', '-c', 'user.name=x', '-C', '/tmp/r', 'commit', '-m', 'x']],
    '--no-pager' => [['git', '--no-pager', 'commit', '-v', '-s']],
    '-S i --gpg-sign=id' => [['git', 'commit', '-S', '--gpg-sign=ABC', '-m', 'x']],
    '--cleanup=strip' => [['git', 'commit', '--cleanup=strip', '-F', 'plik']],
]);

it('tryb commita: --amend to Amend', function (array $argv): void {
    expect(scopeMode(ScopeSupport::strings($argv)))->toBe(CommitMode::Amend);
})->with([
    'amend' => [['git', 'commit', '--amend']],
    'amend --no-edit' => [['git', 'commit', '--amend', '--no-edit']],
    'amend z -m i --allow-empty' => [['git', 'commit', '--allow-empty', '--amend', '-m', 'x']],
]);

it('tryb commita: wszystko niepewne to Unknown (baza HEAD^), nigdy Plain', function (?array $argv): void {
    expect(scopeMode($argv === null ? null : ScopeSupport::strings($argv)))->toBe(CommitMode::Unknown);
})->with([
    'brak wiersza poleceń' => [null],
    'pusty wiersz' => [[]],
    'skrócona opcja --amen' => [['git', 'commit', '--amen']],
    'skrócona opcja --amend=x' => [['git', 'commit', '--amend=x']],
    'alias' => [['git', 'ci', '--amend']],
    'alias bez amend' => [['git', 'ci', '-m', 'x']],
    'opcja spoza listy' => [['git', 'commit', '--fixup', 'HEAD']],
    '-C (reuse message)' => [['git', 'commit', '-C', 'HEAD']],
    '-c (reedit message)' => [['git', 'commit', '-c', 'HEAD']],
    'pathspec' => [['git', 'commit', '-m', 'x', 'plik.md']],
    'separator --' => [['git', 'commit', '-m', 'x', '--', 'plik.md']],
    '-m bez wartości' => [['git', 'commit', '-m']],
    'tylko myślnik' => [['git', 'commit', '-']],
    'nieznana opcja globalna' => [['git', '--work-tree=/x', 'commit']],
    'opcja -c bez wartości' => [['git', '-c']],
    'inny program' => [['sh', '-c', 'git commit']],
    'inny program o nazwie z git' => [['mygit', 'commit']],
    'wrapper' => [['husky', 'git', 'commit']],
    'inne polecenie git' => [['git', 'merge', '--no-ff']],
    'sam git' => [['git']],
    'wiele opcji z jedną nieznaną' => [['git', 'commit', '-q', '--verbose', '--dry-run']],
    'ps: wiadomość z odstępem rozpada się na tokeny' => [['git', 'commit', '-m', 'fix', 'thing']],
]);

it('źródło argumentów procesu jest zastępowalne (atrapa)', function (): void {
    $source = new class implements CommandLineSource {
        public function argv(int $pid): ?array
        {
            return $pid === 7 ? ['git', 'commit'] : null;
        }
    };

    expect((new CommitModeDetector())->detect($source->argv(7)))->toBe(CommitMode::Plain)
        ->and((new CommitModeDetector())->detect($source->argv(8)))->toBe(CommitMode::Unknown);
});
