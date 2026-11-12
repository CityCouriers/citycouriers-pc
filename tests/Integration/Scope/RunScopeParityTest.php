<?php

declare(strict_types=1);

use CityCouriers\Pc\Engine\CheckedUnit;
use CityCouriers\Pc\Engine\GateMode;
use CityCouriers\Pc\Engine\GateTriggers;
use CityCouriers\Pc\Engine\ProcessGit;
use CityCouriers\Pc\Scope\Profile;
use CityCouriers\Pc\Scope\ProcessGitRunner;
use CityCouriers\Pc\Scope\ScopeDecider;
use CityCouriers\Pc\Scope\ScopeResolver;
use CityCouriers\Pc\Tests\Support\EventLog;
use CityCouriers\Pc\Tests\Support\FakeGate;
use CityCouriers\Pc\Tests\Support\GitRepo;
use CityCouriers\Pc\Tests\Support\Harness;
use CityCouriers\Pc\Tests\Support\ScopeSupport;

/**
 * Parzystość `pc run --staged --profile=local-narrow` z `pc scope --profile=local-narrow` na prawdziwym repozytorium
 * i indeksie (#40): gdy klasyfikator daje pełny zestaw (brak reguły, bezpiecznik, szczególny typ pliku, błąd gita, pusty
 * zakres), żadna bramka silnika nie może być `skipped`. Bramka PHP z wyzwalaczami jest tu wskaźnikiem pominięcia.
 */
function parityRepo(): GitRepo
{
    $repo = new GitRepo();
    $repo->history([['README.md' => "r\n", 'src/Kod.php' => "<?php\n"], ['docs/b.md' => "b\n"]]);
    $repo->write('scripts/quality/scope-rules.json', (string) file_get_contents(ScopeSupport::root() . '/scripts/quality/scope-rules.json'));

    return $repo;
}

/**
 * @return array{bool, array<string, string>} czy `pc scope` daje pełny zestaw oraz statusy bramek z `pc run`
 */
function parityRun(GitRepo $repo): array
{
    $log = new EventLog();
    $php = (new FakeGate('phpstan', GateMode::Blocking, false, CheckedUnit::Checks, $log))
        ->withTriggers(new GateTriggers(['php'], ['src/'], ['phpstan.neon'], 'Zmiana bez PHP.'));
    $always = new FakeGate('format', GateMode::Blocking, false, CheckedUnit::Checks, $log);
    [, $document] = Harness::runJson([$php, $always], ['--staged', '--profile=local-narrow'], null, new ProcessGit($repo->path));
    $decision = (new ScopeDecider(new ScopeResolver(new ProcessGitRunner($repo->path))))
        ->decide(ScopeSupport::rules(), Profile::LocalNarrow, null, null);

    return [$decision->selection->full, Harness::statuses($document)];
}

it('pc run i pc scope: pełny zestaw u klasyfikatora oznacza, że żadna bramka silnika nie jest pominięta', function (string $label, callable $arrange): void {
    $repo = parityRepo();
    $arrange($repo);

    [$full, $statuses] = parityRun($repo);
    $repo->remove();

    expect($full)->toBeTrue($label)
        ->and($statuses)->toBe(['format' => 'passed', 'phpstan' => 'passed'], $label);
})->with([
    'README.md i nieznana ścieżka (brak reguły)' => ['no_rule', function (GitRepo $repo): void {
        $repo->write('README.md', "zmiana\n");
        $repo->write('custom/unknown.xyz', "x\n");
        $repo->stage('README.md', 'custom/unknown.xyz');
    }],
    'zmiana pliku bezpiecznika (fuse) obok README.md' => ['fuse', function (GitRepo $repo): void {
        $repo->write('README.md', "zmiana\n");
        $repo->write('scripts/quality/scope-rules.json', (string) file_get_contents(ScopeSupport::root() . '/scripts/quality/scope-rules.json') . ' ');
        $repo->stage('README.md', 'scripts/quality/scope-rules.json');
    }],
    'pusty stage' => ['pusty', function (GitRepo $repo): void {
        $repo->stage('README.md');
    }],
    'dowiązanie symboliczne obok README.md' => ['symlink', function (GitRepo $repo): void {
        $repo->write('README.md', "zmiana\n");
        $repo->stage('README.md');
        $blob = $repo->gitWithInput("cel\n", 'hash-object', '-w', '--stdin');
        $repo->git('update-index', '--add', '--cacheinfo', '120000,' . $blob . ',docs/link.md');
    }],
    'submoduł obok README.md' => ['submoduł', function (GitRepo $repo): void {
        $repo->write('README.md', "zmiana\n");
        $repo->stage('README.md');
        $repo->git('update-index', '--add', '--cacheinfo', '160000,' . $repo->git('rev-parse', 'HEAD') . ',docs/modul');
    }],
]);

it('pc run i pc scope: czysty README.md zawęża zakres w obu wejściach (pominięcie jest dozwolone)', function (): void {
    $repo = parityRepo();
    $repo->write('README.md', "zmiana\n");
    $repo->stage('README.md');

    [$full, $statuses] = parityRun($repo);
    $repo->remove();

    expect($full)->toBeFalse()
        ->and($statuses)->toBe(['format' => 'passed', 'phpstan' => 'skipped']);
});

it('pc run: brak pliku reguł albo błąd gita to pełny zakres, nigdy pominięcie', function (): void {
    $repo = new GitRepo();
    $repo->history([['README.md' => "r\n"], ['docs/b.md' => "b\n"]]);
    $repo->write('README.md', "zmiana\n");
    $repo->stage('README.md');
    $withoutRules = new ProcessGit($repo->path);
    $notRepository = new ProcessGit(sys_get_temp_dir());

    expect($withoutRules->scopePaths())->toBeNull()
        ->and($notRepository->scopePaths())->toBeNull();
    $repo->remove();
});

it('ProcessGit z regułami i czystym README.md zwraca ścieżki do zawężenia', function (): void {
    $repo = parityRepo();
    $repo->write('README.md', "zmiana\n");
    $repo->stage('README.md');

    expect((new ProcessGit($repo->path))->scopePaths())->toContain('README.md');
    $repo->remove();
});
