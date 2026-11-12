<?php

declare(strict_types=1);

use CityCouriers\Pc\Scope\ProcessGitRunner;
use CityCouriers\Pc\Tests\Support\GitRepo;
use CityCouriers\Pc\Tests\Support\PosixShell;

/**
 * Regresja: zmienna środowiska o nazwie z samych cyfr (np. `1234`) trafia do tablic PHP jako klucz int.
 * Funkcje z typem `string` rzucały wtedy TypeError (`strict_types`), a `proc_open` nie potrafi takiej zmiennej
 * przekazać (Windows: błąd CreateProcess, Linux: wpis ignorowany), więc musi być pomijana.
 */
afterEach(function (): void {
    putenv('1234');
});

it('środowisko repozytorium testowego pomija zmienną o nazwie liczbowej i uruchamia git', function (): void {
    putenv('1234=x');
    $repo = new GitRepo();
    $environment = $repo->environment();
    $gitDir = $repo->git('rev-parse', '--git-dir');
    $repo->remove();

    expect(array_filter(array_keys($environment), is_int(...)))->toBe([])
        ->and($gitDir)->toBe('.git');
});

it('czyszczenie środowiska procesu testowego nie rzuca przy zmiennej o nazwie liczbowej', function (): void {
    putenv('1234=x');
    GitRepo::scrubProcessEnvironment();

    expect(getenv('1234'))->toBe('x');
});

it('środowisko powłoki testów pomija zmienną o nazwie liczbowej', function (): void {
    putenv('1234=x');

    expect(array_filter(array_keys(PosixShell::environment()), is_int(...)))->toBe([]);
});

it('jawnie podana zmienna o nazwie liczbowej w GitRepo to błąd konfiguracji', function (): void {
    $repo = new GitRepo();
    $repo->extraEnvironment = ['1234' => 'x'];

    expect(static fn(): array => $repo->environment())->toThrow(InvalidArgumentException::class, '1234');
    $repo->remove();
});

it('jawnie podana zmienna o nazwie liczbowej w PosixShell::environment to błąd konfiguracji', function (): void {
    expect(static fn(): array => PosixShell::environment(['1234' => 'x']))->toThrow(InvalidArgumentException::class, '1234');
});

it('ProcessGitRunner uruchamia git przy zmiennej o nazwie liczbowej', function (): void {
    putenv('1234=x');
    $repo = new GitRepo();
    $output = (new ProcessGitRunner($repo->path))->run(['rev-parse', '--git-dir']);
    $repo->remove();

    expect($output->code)->toBe(0);
});
