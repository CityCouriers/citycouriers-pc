<?php

declare(strict_types=1);

use CityCouriers\Pc\Scope\ProcessCommandLine;
use CityCouriers\Pc\Scope\ProcessGitRunner;
use CityCouriers\Pc\Scope\ScopeCommand;
use CityCouriers\Pc\Scope\ScopeDecider;
use CityCouriers\Pc\Scope\ScopeResolver;
use CityCouriers\Pc\Tests\Support\GitRepo;
use CityCouriers\Pc\Tests\Support\ScopeSupport;

/**
 * @param array<string, string> $environment
 */
function scopeCommandFor(GitRepo $repo, array $environment = []): ScopeCommand
{
    return new ScopeCommand(new ScopeDecider(new ScopeResolver(new ProcessGitRunner($repo->path)), new ProcessCommandLine(), $environment));
}

/**
 * @return list<string>
 */
function scopeRulesArgument(): array
{
    return ['--rules=' . ScopeSupport::root() . '/scripts/quality/scope-rules.json'];
}

it('pc scope: nieznana opcja, powtórzenie i brak wartości to błąd użycia (kod 2)', function (array $arguments): void {
    $repo = new GitRepo();
    $result = scopeCommandFor($repo)->execute(ScopeSupport::strings($arguments));

    expect($result->exitCode)->toBe(2)
        ->and($result->stdout)->toBe('')
        ->and($result->stderr)->toContain('Użycie: pc scope');
    $repo->remove();
})->with([
    'nieznana opcja' => [['--nope=1']],
    'bez wartości' => [['--profile=']],
    'argument pozycyjny' => [['local-narrow']],
    'powtórzenie' => [['--profile=enforce-all', '--profile=local-narrow']],
]);

it('pc scope: brak pliku reguł to kod 1 z komunikatem, bez bramek na stdout', function (): void {
    $repo = new GitRepo();
    $result = scopeCommandFor($repo)->execute(['--rules=' . $repo->path . '/nie-ma.json']);

    expect($result->exitCode)->toBe(1)
        ->and($result->stdout)->toBe('')
        ->and($result->stderr)->toContain('[scope] BŁĄD');
    $repo->remove();
});

it('pc scope: stdout to nazwy bramek, stderr to wyjaśnienia, nieznany profil to enforce-all z uwagą', function (): void {
    $repo = new GitRepo();
    $repo->history([['README.md' => "r\n"]]);
    $repo->write('docs/a.md', "a\n");
    $repo->stage('docs/a.md');
    $all = implode(' ', ScopeSupport::rules()->gates) . "\n";

    $unknown = scopeCommandFor($repo)->execute([...scopeRulesArgument(), '--profile=local-narow']);
    $enforce = scopeCommandFor($repo)->execute([...scopeRulesArgument(), '--profile=enforce-all']);
    $narrow = scopeCommandFor($repo)->execute([...scopeRulesArgument(), '--profile=local-narrow']);

    expect($unknown->exitCode)->toBe(0)
        ->and($unknown->stdout)->toBe($all)
        ->and($unknown->stderr)->toContain('nieznany profil')
        ->and($enforce->stdout)->toBe($all)
        ->and($narrow->exitCode)->toBe(0)
        ->and($narrow->stdout)->toBe("privacy\n")
        ->and($narrow->stderr)->not->toBe('');
    $repo->remove();
});

it('pc scope: PC_HOOK_SCOPE=all wymusza pełny zestaw nawet w local-narrow', function (): void {
    $repo = new GitRepo();
    $repo->history([['README.md' => "r\n"]]);
    $repo->write('docs/a.md', "a\n");
    $repo->stage('docs/a.md');

    $result = scopeCommandFor($repo, ['PC_HOOK_SCOPE' => 'all'])->execute([...scopeRulesArgument(), '--profile=local-narrow']);

    expect($result->stdout)->toBe(implode(' ', ScopeSupport::rules()->gates) . "\n");
    $repo->remove();
});

it('pc scope: --base klasyfikuje zakres od bazy do HEAD, a nierozwiązywalna baza daje pełny zestaw', function (): void {
    $repo = new GitRepo();
    $repo->history([['README.md' => "r\n"], ['docs/a.md' => "a\n"], ['src/Kod.php' => "<?php\n"]]);
    $docsOnly = $repo->git('rev-parse', 'HEAD~1');
    $all = implode(' ', ScopeSupport::rules()->gates) . "\n";

    $withCode = scopeCommandFor($repo)->execute([...scopeRulesArgument(), '--profile=local-narrow', '--base=HEAD~2']);
    $broken = scopeCommandFor($repo)->execute([...scopeRulesArgument(), '--profile=local-narrow', '--base=nie-ma-takiej-rewizji']);

    expect($docsOnly)->not->toBe('')
        ->and($withCode->stdout)->toBe("privacy lint stan smells test negative\n")
        ->and($broken->exitCode)->toBe(0)
        ->and($broken->stdout)->toBe($all);
    $repo->remove();
});

it('środowisko hosta (GIT_DIR, GIT_INDEX_FILE, PC_HOOK_SCOPE) nie przecieka do repozytorium testowego', function (): void {
    putenv('GIT_DIR=' . ScopeSupport::root() . '/.git');
    putenv('GIT_INDEX_FILE=' . ScopeSupport::root() . '/.git/index');
    putenv('PC_HOOK_SCOPE=all');

    $repo = new GitRepo();
    $repo->history([['README.md' => "r
"]]);
    $repo->write('docs/a.md', "a
");
    $repo->stage('docs/a.md');
    $result = scopeCommandFor($repo)->execute([...scopeRulesArgument(), '--profile=local-narrow']);

    expect(getenv('GIT_DIR'))->toBeFalse()
        ->and(getenv('GIT_INDEX_FILE'))->toBeFalse()
        ->and(getenv('PC_HOOK_SCOPE'))->toBeFalse()
        ->and($result->stdout)->toBe("privacy
");
    $repo->remove();
});
