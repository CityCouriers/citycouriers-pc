<?php

declare(strict_types=1);

use CityCouriers\Pc\Tests\Support\ScopeSupport;
use CityCouriers\Pc\Engine\CheckedUnit;
use CityCouriers\Pc\Engine\GateMode;
use CityCouriers\Pc\Engine\GateTriggers;
use CityCouriers\Pc\Tests\Support\EventLog;
use CityCouriers\Pc\Tests\Support\FakeGate;
use CityCouriers\Pc\Tests\Support\FakeGit;
use CityCouriers\Pc\Tests\Support\Harness;

function scopedGate(string $name, GateTriggers $triggers, EventLog $log): FakeGate
{
    return (new FakeGate($name, GateMode::Blocking, false, CheckedUnit::Checks, $log))->withTriggers($triggers);
}

function phpTriggers(): GateTriggers
{
    return new GateTriggers(['php'], ['src/', 'bin/pc', 'rulesets/*.xml'], ['phpstan.neon'], 'Zmiana bez PHP.');
}

it('wyzwalacze bramki: rozszerzenia, prefiksy, wzorce i pliki konfiguracji uruchamiają bramkę', function (array $paths): void {
    expect(phpTriggers()->matches(ScopeSupport::strings($paths)))->toBeTrue();
})->with([
    'rozszerzenie' => [['lib/x.php']],
    'prefiks katalogu' => [['src/dane.txt']],
    'ścieżka dokładna' => [['bin/pc']],
    'wzorzec' => [['rulesets/phpmd.xml']],
    'plik konfiguracji (pełny zakres)' => [['README.md', 'phpstan.neon']],
    'jeden pasujący wśród wielu' => [['README.md', 'docs/a.md', 'x.php']],
    'dziwna ścieżka nie zwalnia bramki' => [['README.md', 'a\\b.md']],
    'ścieżka z ..' => [['README.md', '../x.md']],
    'pusta ścieżka' => [['README.md', '']],
]);

it('wyzwalacze bramki: pliki bez wyzwalacza nie uruchamiają bramki, ale tylko przy jawnej allowliście', function (): void {
    expect(phpTriggers()->matches(['README.md', 'docs/a.md']))->toBeFalse()
        ->and(phpTriggers()->matches(['docs/x.PHP']))->toBeFalse()
        ->and(phpTriggers()->matches(['rulesets/glebiej/x.xml']))->toBeFalse()
        ->and(phpTriggers()->matches([]))->toBeFalse();
});

it('pusta deklaracja wyzwalaczy oznacza „zawsze” (fail-closed)', function (): void {
    expect((new GateTriggers())->matches(['README.md']))->toBeTrue()
        ->and((new GateTriggers())->matches([]))->toBeTrue();
});

it('local-narrow: bramka bez plików wyzwalających jest pominięta z powodem no_matching_files, a kod wyjścia to 0', function (): void {
    $log = new EventLog();
    $git = new FakeGit(true, ['README.md'], [], false, ['README.md']);

    [$result, $document] = Harness::runJson([scopedGate('phpstan', phpTriggers(), $log), scopedGate('format', new GateTriggers(), $log)], ['--staged', '--profile=local-narrow'], null, $git);

    expect($result->exitCode)->toBe(0)
        ->and(Harness::statuses($document))->toBe(['format' => 'passed', 'phpstan' => 'skipped'])
        ->and(Harness::at($document, 'gates.1.reason.code'))->toBe('no_matching_files')
        ->and(Harness::at($document, 'gates.1.reason.message'))->toBe('Zmiana bez PHP.')
        ->and(Harness::at($document, 'summary.skipped'))->toBe(1)
        ->and(Harness::at($document, 'summary.passed'))->toBe(1)
        ->and($log->lines)->not->toContain('run:phpstan')->toContain('run:format')
        ->and(Harness::schemaErrors($document))->toBe([]);
});

it('pominięta bramka nie liczy się jako przeszła, a bramka nie przeskanowana daje kod 4', function (): void {
    $log = new EventLog();
    $git = new FakeGit(true, ['README.md'], [], false, ['README.md']);
    $missing = scopedGate('format', new GateTriggers(), $log)->missingTool();

    [$result, $document] = Harness::runJson([scopedGate('phpstan', phpTriggers(), $log), $missing], ['--staged', '--profile=local-narrow'], null, $git);

    expect($result->exitCode)->toBe(4)
        ->and(Harness::at($document, 'summary.passed'))->toBe(0)
        ->and(Harness::at($document, 'summary.skipped'))->toBe(1)
        ->and(Harness::at($document, 'summary.not_scanned'))->toBe(1)
        ->and(Harness::at($document, 'complete'))->toBeFalse();
});

it('kontrola ujemna: enforce-all, brak profilu i nieznany profil uruchamiają bramkę mimo samej dokumentacji', function (array $arguments): void {
    $log = new EventLog();
    $git = new FakeGit(true, ['README.md'], [], false, ['README.md']);

    [$result, $document] = Harness::runJson([scopedGate('phpstan', phpTriggers(), $log)], ['--staged', ...ScopeSupport::strings($arguments)], null, $git);

    expect($result->exitCode)->toBe(0)
        ->and(Harness::statuses($document))->toBe(['phpstan' => 'passed'])
        ->and($log->lines)->toContain('run:phpstan');
})->with([
    'enforce-all' => [['--profile=enforce-all']],
    'brak profilu' => [[]],
    'nieznany profil' => [['--profile=local-narow']],
    'pusty profil' => [['--profile=']],
    'inna wielkość liter' => [['--profile=LOCAL-NARROW']],
]);

it('kontrola ujemna: local-narrow nie pomija bramki, gdy zakresu nie da się zawęzić albo plik ją wyzwala', function (string $label, FakeGit $git, array $arguments): void {
    $log = new EventLog();

    [, $document] = Harness::runJson([scopedGate('phpstan', phpTriggers(), $log)], [...ScopeSupport::strings($arguments), '--profile=local-narrow'], null, $git);

    expect(Harness::statuses($document))->toBe(['phpstan' => 'passed'], $label);
})->with([
    'brak wiarygodnej listy (null)' => ['null', new FakeGit(true, ['README.md'], [], false, null), ['--staged']],
    'plik PHP w zmianie' => ['php', new FakeGit(true, ['README.md', 'src/A.php'], [], false, ['README.md', 'src/A.php']), ['--staged']],
    'pełny zakres (bez --staged)' => ['full', new FakeGit(true, ['README.md'], [], false, ['README.md']), []],
    'plik konfiguracji' => ['config', new FakeGit(true, ['phpstan.neon'], [], false, ['phpstan.neon']), ['--staged']],
]);
