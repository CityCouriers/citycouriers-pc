<?php

declare(strict_types=1);

use CityCouriers\Pc\Tests\Support\Child;
use CityCouriers\Pc\Tests\Support\GitRepo;
use CityCouriers\Pc\Tests\Support\PosixShell;

/**
 * Regresja: wynik testów nie może zależeć od tego, w jakiej konsoli biegną (Git Bash, PowerShell, cmd). W PowerShell
 * `bash` z PATH to wrapper WSL, a `Path` i `PATH` bywają dwiema zmiennymi (CI na Linuksie tego nie złapie).
 */
it('powłoka testów to bash z Gita na Windows, nie wrapper WSL', function (): void {
    $pipes = [];
    $process = Child::open([PosixShell::bash(), '-c', 'uname -o'], $pipes, null, PosixShell::environment());
    if ($process === false) {
        throw new RuntimeException('Nie udało się uruchomić bash.');
    }
    $name = trim((string) stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    expect(strtolower($name))->toBe(PHP_OS_FAMILY === 'Windows' ? 'msys' : strtolower(PHP_OS_FAMILY === 'Darwin' ? 'Darwin' : 'GNU/Linux'));
});

it('środowisko repozytorium testowego nie ma dwóch zmiennych różniących się tylko wielkością liter', function (): void {
    $repo = new GitRepo();
    $repo->extraEnvironment = ['PATH' => '/jawna/sciezka'];
    $names = array_map(strtoupper(...), array_keys($repo->environment()));

    expect($repo->environment()['PATH'])->toBe('/jawna/sciezka')
        ->and(count($names))->toBe(count(array_unique($names)));
    $repo->remove();
});
