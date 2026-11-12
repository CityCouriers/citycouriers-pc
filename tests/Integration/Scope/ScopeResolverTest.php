<?php

declare(strict_types=1);

use CityCouriers\Pc\Tests\Support\ScopeSupport;
use CityCouriers\Pc\Scope\Classifier;
use CityCouriers\Pc\Scope\CommitMode;
use CityCouriers\Pc\Scope\CommitModeDetector;
use CityCouriers\Pc\Scope\DiffParser;
use CityCouriers\Pc\Scope\GitOutput;
use CityCouriers\Pc\Scope\GitRunner;
use CityCouriers\Pc\Scope\ParsedDiff;
use CityCouriers\Pc\Scope\ProcessGitRunner;
use CityCouriers\Pc\Scope\ScopeException;
use CityCouriers\Pc\Tests\Support\RawDiff;
use CityCouriers\Pc\Scope\ScopeInput;
use CityCouriers\Pc\Scope\ScopeResolver;
use CityCouriers\Pc\Tests\Support\GitRepo;

/**
 * Repozytorium z kodem w HEAD: c0 (README.md) i c1 (src/Kod.php).
 */
function scopeRepoWithCode(): GitRepo
{
    $repo = new GitRepo();
    $repo->commit(['README.md' => "start\n"], 'c0');
    $repo->commit(['src/Kod.php' => "<?php\n"], 'c1');

    return $repo;
}

function scopeResolve(GitRepo $repo, CommitMode $mode): ScopeInput
{
    return (new ScopeResolver(new ProcessGitRunner($repo->path)))->staged($mode);
}

/**
 * Bramki dla wejścia: brak ustalonej listy to pełny zestaw (tak robi `ScopeDecider`).
 *
 * @return list<string>
 */
function scopeGatesOf(ScopeInput $input): array
{
    $classifier = new Classifier(ScopeSupport::rules());
    if ($input->unresolvedReason !== null) {
        return $classifier->gates();
    }

    return $classifier->classify(new ParsedDiff($input->paths, $input->specialModes))->gates;
}

/**
 * @param list<string> $paths
 *
 * @return list<string>
 */
function scopeGatesForPaths(array $paths): array
{
    return (new Classifier(ScopeSupport::rules()))->classify(new ParsedDiff($paths, false))->gates;
}

/**
 * @param list<string> $subset
 * @param list<string> $superset
 */
function scopeIsSubset(array $subset, array $superset): bool
{
    return array_diff($subset, $superset) === [];
}

const SCOPE_PHP_GATES = ['lint', 'stan', 'smells', 'test', 'negative'];

it('zwykły commit z samym .md po commicie z kodem: tylko privacy (skracanie działa przy potwierdzonym trybie)', function (): void {
    $repo = scopeRepoWithCode();
    $repo->commit(['docs/a.md' => "a\n"], 'tmp');
    $repo->git('reset', '--soft', 'HEAD~1');

    $input = scopeResolve($repo, CommitMode::Plain);

    expect($input->paths)->toBe(['docs/a.md'])->and(scopeGatesOf($input))->toBe(['privacy']);
    $repo->remove();
});

it('ten sam stan przy trybie nieznanym albo amend: baza HEAD^ dodaje bramki PHP, nigdy nie zawęża', function (CommitMode $mode): void {
    $repo = scopeRepoWithCode();
    $repo->write('docs/a.md', "a\n");
    $repo->stage('docs/a.md');

    $gates = scopeGatesOf(scopeResolve($repo, $mode));

    expect($gates)->toContain(...SCOPE_PHP_GATES)->and($gates)->toContain('privacy');
    $repo->remove();
})->with([CommitMode::Unknown, CommitMode::Amend]);

it('zwykły pusty stage (--allow-empty): pełny zestaw (negatyw)', function (): void {
    $repo = scopeRepoWithCode();

    $input = scopeResolve($repo, CommitMode::Plain);

    expect($input->paths)->toBe([])->and(scopeGatesOf($input))->toBe(ScopeSupport::rules()->gates);
    $repo->remove();
});

it('--amend --no-edit na bieżącym HEAD z kodem PHP bez nowych zmian: bramki PHP biegną (negatyw: lista tylko ze stage\'a byłaby pusta)', function (CommitMode $mode): void {
    $repo = scopeRepoWithCode();

    $input = scopeResolve($repo, $mode);

    // Pusty stage przy amend to nie „brak bramek”: lista pusta wymusza pełny zestaw, więc bramki PHP biegną.
    expect($repo->git('diff', '--cached', '--name-only'))->toBe('')
        ->and($input->paths)->toBe([])
        ->and(scopeGatesOf($input))->toBe(ScopeSupport::rules()->gates)
        ->and(scopeGatesOf($input))->toContain(...SCOPE_PHP_GATES);
    $repo->remove();
})->with([CommitMode::Amend, CommitMode::Unknown]);

it('--amend z dodanym samym .md na HEAD z kodem PHP: bramki PHP biegną (negatyw: git diff --cached widzi tylko .md)', function (): void {
    $repo = scopeRepoWithCode();
    $repo->write('docs/a.md', "a\n");
    $repo->stage('docs/a.md');

    $input = scopeResolve($repo, CommitMode::Amend);

    expect($repo->git('diff', '--cached', '--name-only'))->toBe('docs/a.md')
        ->and($input->paths)->toContain('src/Kod.php', 'docs/a.md')
        ->and(scopeGatesOf($input))->toContain(...SCOPE_PHP_GATES);
    $repo->remove();
});

it('stan, w którym indeks cofa zmianę z HEAD: zwykły commit widzi usunięcie kodu, a tryb niepewny nie daje węższego zestawu', function (): void {
    $repo = scopeRepoWithCode();
    $repo->git('rm', '--quiet', '--cached', 'src/Kod.php');

    // Amend porównałby HEAD^ z indeksem (zmian brak), zwykły commit HEAD z indeksem (usunięcie kodu).
    expect($repo->git('diff', '--cached', '--name-only', 'HEAD^'))->toBe('')
        ->and(scopeGatesOf(scopeResolve($repo, CommitMode::Plain)))->toContain(...SCOPE_PHP_GATES)
        ->and(scopeResolve($repo, CommitMode::Plain)->paths)->toBe(['src/Kod.php'])
        ->and(scopeGatesOf(scopeResolve($repo, CommitMode::Unknown)))->toBe(ScopeSupport::rules()->gates);
    $repo->remove();
});

it('pierwszy commit (brak HEAD): bez błędu, baza puste drzewo; pusty stage daje pełny zestaw', function (): void {
    $repo = new GitRepo();
    $repo->write('README.md', "r\n");
    $repo->stage('README.md');

    $input = scopeResolve($repo, CommitMode::Plain);
    expect($input->unresolvedReason)->toBeNull()
        ->and($input->paths)->toBe(['README.md'])
        ->and($input->description)->toContain('puste drzewo')
        ->and(scopeGatesOf($input))->toBe(['privacy'])
        ->and(scopeResolve($repo, CommitMode::Unknown)->paths)->toBe(['README.md']);

    $empty = new GitRepo();
    $emptyInput = scopeResolve($empty, CommitMode::Plain);
    expect($emptyInput->unresolvedReason)->toBeNull()
        ->and($emptyInput->paths)->toBe([])
        ->and(scopeGatesOf($emptyInput))->toBe(ScopeSupport::rules()->gates);
    $repo->remove();
    $empty->remove();
});

it('amend korzenia (HEAD bez rodzica): baza puste drzewo, bramki PHP biegną mimo pustego stage\'a', function (): void {
    $repo = new GitRepo();
    $repo->commit(['src/Kod.php' => "<?php\n"], 'root');

    $input = scopeResolve($repo, CommitMode::Amend);

    expect($input->unresolvedReason)->toBeNull()
        ->and($input->paths)->toBe([])
        ->and(scopeGatesOf($input))->toContain(...SCOPE_PHP_GATES);

    $repo->write('docs/a.md', "a
");
    $repo->stage('docs/a.md');
    $withDocs = scopeResolve($repo, CommitMode::Amend);
    expect($withDocs->paths)->toContain('src/Kod.php', 'docs/a.md')->and(scopeGatesOf($withDocs))->toContain(...SCOPE_PHP_GATES);
    $repo->remove();
});

/**
 * Repozytorium z konfliktem między `main` a gałęzią `b` (plik f.txt). Zwraca repo na `main`.
 */
function scopeConflictRepo(): GitRepo
{
    $repo = new GitRepo();
    $repo->commit(['f.txt' => "a\n"], 'base');
    $repo->git('switch', '--quiet', '-c', 'b');
    $repo->commit(['f.txt' => "b\n"], 'na-b');
    $repo->git('switch', '--quiet', 'main');
    $repo->commit(['f.txt' => "c\n"], 'na-main');

    return $repo;
}

it('operacja w toku (merge, cherry-pick, revert, rebase): pełny zestaw, powód z nazwą operacji', function (string $operation, string $marker): void {
    $repo = scopeConflictRepo();
    match ($operation) {
        'merge' => $repo->run(['merge', 'b']),
        'cherry-pick' => $repo->run(['cherry-pick', 'b']),
        'revert' => $repo->run(['revert', '--no-edit', 'HEAD~1']),
        default => $repo->run(['rebase', 'b']),
    };
    $repo->write('f.txt', "rozwiazane\n");
    $repo->stage('f.txt');

    foreach ([CommitMode::Plain, CommitMode::Unknown] as $mode) {
        $input = scopeResolve($repo, $mode);
        expect($input->unresolvedReason)->toContain($marker)
            ->and(scopeGatesOf($input))->toBe(ScopeSupport::rules()->gates);
    }
    $repo->remove();
})->with([
    'merge' => ['merge', 'MERGE_HEAD'],
    'cherry-pick' => ['cherry-pick', 'CHERRY_PICK_HEAD'],
    'revert' => ['revert', 'REVERT_HEAD'],
    'rebase' => ['rebase', 'rebase-'],
]);

it('merge bez konfliktu zatrzymany przed commitem (--no-commit) też daje pełny zestaw', function (): void {
    $repo = new GitRepo();
    $repo->commit(['README.md' => "r\n"], 'base');
    $repo->git('switch', '--quiet', '-c', 'b');
    $repo->commit(['inny.md' => "i\n"], 'na-b');
    $repo->git('switch', '--quiet', 'main');
    $repo->commit(['src/Kod.php' => "<?php\n"], 'na-main');
    $repo->git('merge', '--no-commit', '--no-ff', 'b');

    expect(scopeResolve($repo, CommitMode::Plain)->unresolvedReason)->toContain('MERGE_HEAD');
    $repo->remove();
});

it('repozytorium płytkie (shallow): pełny zestaw, bo HEAD^ nie jest wiarygodny', function (): void {
    $origin = scopeRepoWithCode();
    $location = 'file://' . (str_starts_with($origin->path, '/') ? '' : '/') . str_replace('\\', '/', $origin->path);
    $target = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pcscope' . bin2hex(random_bytes(6));
    $origin->git('clone', '--quiet', '--depth', '1', $location, $target);
    $clone = new GitRepo($target);
    $clone->write('docs/a.md', "a\n");
    $clone->stage('docs/a.md');

    foreach ([CommitMode::Plain, CommitMode::Unknown] as $mode) {
        $input = scopeResolve($clone, $mode);
        expect($input->unresolvedReason)->toContain('płytkie')->and(scopeGatesOf($input))->toBe(ScopeSupport::rules()->gates);
    }
    $clone->remove();
    $origin->remove();
});

it('detached HEAD: ten sam wynik co na gałęzi (baza to HEAD, nie nazwa gałęzi)', function (): void {
    $repo = scopeRepoWithCode();
    $repo->write('docs/a.md', "a\n");
    $repo->stage('docs/a.md');
    $onBranch = [scopeResolve($repo, CommitMode::Plain)->paths, scopeResolve($repo, CommitMode::Unknown)->paths];

    $repo->git('checkout', '--quiet', '--detach');

    expect([scopeResolve($repo, CommitMode::Plain)->paths, scopeResolve($repo, CommitMode::Unknown)->paths])->toBe($onBranch)
        ->and(scopeGatesOf(scopeResolve($repo, CommitMode::Plain)))->toBe(['privacy']);
    $repo->remove();
});

it('dowiązanie symboliczne i submoduł w indeksie: pełny zestaw', function (string $mode, string $name): void {
    $repo = scopeRepoWithCode();
    $blob = $repo->gitWithInput("cel\n", 'hash-object', '-w', '--stdin');
    $object = $mode === '160000' ? $repo->git('rev-parse', 'HEAD') : $blob;
    $repo->git('update-index', '--add', '--cacheinfo', $mode . ',' . $object . ',' . $name);
    $repo->write('docs/a.md', "a\n");
    $repo->stage('docs/a.md');

    $input = scopeResolve($repo, CommitMode::Plain);

    expect($input->specialModes)->toBeTrue()->and(scopeGatesOf($input))->toBe(ScopeSupport::rules()->gates);
    $repo->remove();
})->with([['120000', 'link'], ['160000', 'modul']]);

it('niezaufany diff w prawdziwym repo: konflikt w indeksie (status U) daje pełny zestaw z powodem', function (): void {
    $repo = scopeRepoWithCode();
    $one = $repo->gitWithInput("1\n", 'hash-object', '-w', '--stdin');
    $two = $repo->gitWithInput("2\n", 'hash-object', '-w', '--stdin');
    $repo->gitWithInput("100644 {$one} 1\tkonflikt.md\n100644 {$two} 2\tkonflikt.md\n", 'update-index', '--index-info');

    $input = scopeResolve($repo, CommitMode::Plain);

    expect($input->unresolvedReason)->toContain('Niezaufany diff')->and(scopeGatesOf($input))->toBe(ScopeSupport::rules()->gates);
    $repo->remove();
});

it('uszkodzony .git: pełny zestaw z komunikatem', function (): void {
    $repo = scopeRepoWithCode();
    file_put_contents($repo->path . '/.git/HEAD', 'śmieci');

    $input = scopeResolve($repo, CommitMode::Plain);

    expect($input->unresolvedReason)->not->toBeNull()->and(scopeGatesOf($input))->toBe(ScopeSupport::rules()->gates);
    $repo->remove();
});

it('katalog bez repozytorium: pełny zestaw', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pcscope' . bin2hex(random_bytes(6));
    mkdir($directory);

    $input = (new ScopeResolver(new ProcessGitRunner($directory)))->staged(CommitMode::Plain);

    expect($input->unresolvedReason)->not->toBeNull()->and(scopeGatesOf($input))->toBe(ScopeSupport::rules()->gates);
    rmdir($directory);
});

/**
 * Runner, który udaje awarie gita: brak programu, kody ≠ 0 i ucięte wyjście.
 *
 * @param array<string, GitOutput> $answers odpowiedzi po pierwszym argumencie gita; reszta to sukces z pustym wyjściem
 */
function scopeFakeGit(array $answers, bool $missing = false): GitRunner
{
    return new readonly class ($answers, $missing) implements GitRunner {
        /**
         * @param array<string, GitOutput> $answers
         */
        public function __construct(private array $answers, private bool $missing) {}

        public function run(array $arguments): GitOutput
        {
            if ($this->missing) {
                throw new ScopeException('Nie udało się uruchomić gita.');
            }

            return $this->answers[$arguments[0]] ?? new GitOutput(0, '');
        }
    };
}

it('git poza PATH i błędy poleceń gita: pełny zestaw z powodem (nigdy zwolnienie bramki)', function (GitRunner $git, string $reason): void {
    $input = (new ScopeResolver($git))->staged(CommitMode::Plain);

    expect($input->unresolvedReason)->toContain($reason)->and(scopeGatesOf($input))->toBe(ScopeSupport::rules()->gates);
})->with([
    'git poza PATH' => [fn() => scopeFakeGit([], true), 'uruchomić gita'],
    'rev-parse kod 128' => [fn() => scopeFakeGit(['rev-parse' => new GitOutput(128, '')]), 'rev-parse'],
]);

it('ucięty albo zepsuty diff z gita: pełny zestaw', function (string $output): void {
    $git = scopeFakeGit([
        'rev-parse' => new GitOutput(0, "/tmp/x/.git\n"),
        'rev-list' => new GitOutput(0, str_repeat('a', 40) . ' ' . str_repeat('b', 40) . "\n"),
        'diff' => new GitOutput(0, $output),
    ]);
    $input = (new ScopeResolver($git))->staged(CommitMode::Plain);

    expect($input->unresolvedReason)->not->toBeNull();
})->with([
    'ucięte wyjście' => [substr(RawDiff::entry('000000', '100644', 'A', 'a.md'), 0, -3)],
    'token spoza formatu' => ["x\0y\0"],
    'status T' => [RawDiff::entry('100644', '120000', 'T', 'a')],
]);

it('diff z kodem wyjścia ≠ 0 daje pełny zestaw', function (): void {
    $git = scopeFakeGit(['diff' => new GitOutput(129, '')]);

    expect((new ScopeResolver($git))->staged(CommitMode::Plain)->unresolvedReason)->not->toBeNull();
});

it('ścieżka z backslashem w diffie nie zwalnia bramek (klasyfikator zwraca pełny zestaw)', function (): void {
    $parsed = (new DiffParser())->parse(RawDiff::entry('000000', '100644', 'A', 'docs\\a.md'));

    expect(scopeGatesForPaths($parsed->paths))->toBe(ScopeSupport::rules()->gates);
});

/**
 * Stany repozytorium do testu niezmiennika: funkcja przygotowuje repo i zwraca je.
 *
 * @return array<string, Closure(): GitRepo>
 */
function scopeInvariantStates(): array
{
    return [
        'md po kodzie' => function (): GitRepo {
            $repo = scopeRepoWithCode();
            $repo->write('docs/a.md', "a\n");
            $repo->stage('docs/a.md');

            return $repo;
        },
        'pusty stage po kodzie' => fn(): GitRepo => scopeRepoWithCode(),
        'cofnięty kod w indeksie' => function (): GitRepo {
            $repo = scopeRepoWithCode();
            $repo->git('rm', '--quiet', '--cached', 'src/Kod.php');

            return $repo;
        },
        'zmiana kodu i md' => function (): GitRepo {
            $repo = scopeRepoWithCode();
            $repo->write('src/Kod.php', "<?php // zmiana\n");
            $repo->write('docs/a.md', "a\n");
            $repo->stage('src/Kod.php', 'docs/a.md');

            return $repo;
        },
        'korzeń z kodem' => function (): GitRepo {
            $repo = new GitRepo();
            $repo->commit(['src/Kod.php' => "<?php\n"], 'root');

            return $repo;
        },
        'korzeń i md w indeksie' => function (): GitRepo {
            $repo = new GitRepo();
            $repo->commit(['src/Kod.php' => "<?php\n"], 'root');
            $repo->write('README.md', "r\n");
            $repo->stage('README.md');

            return $repo;
        },
    ];
}

it('niezmiennik: błędne wykrycie trybu może tylko dodać bramki (zbiór dla niepewnego sygnału zawiera zbiór dla trybu rzeczywistego)', function (string $state): void {
    $repo = scopeInvariantStates()[$state]();
    $names = static fn(string $output): array => array_values(array_filter(explode("\n", $output)));
    $emptyTree = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';
    $hasParent = $repo->run(['rev-parse', '--verify', '-q', 'HEAD^'])[0] === 0;
    $truthPlain = $names($repo->git('diff', '--cached', '--name-only', 'HEAD'));
    $truthAmend = $names($repo->git('diff', '--cached', '--name-only', $hasParent ? 'HEAD^' : $emptyTree));
    // Pusta lista w trybie rzeczywistym to pełny zestaw (klasyfikator), więc też musi się mieścić w wykrytym.
    $actual = [scopeGatesForPaths($truthPlain), scopeGatesForPaths($truthAmend)];

    $degraded = [
        'brak sygnału' => null,
        'skrócona opcja' => ['git', 'commit', '--amen', '--no-edit'],
        'alias' => ['git', 'ci', '--amend'],
        'opcja spoza listy' => ['git', 'commit', '--fixup=HEAD'],
        'obcy proces' => ['sh', '-c', 'git commit'],
    ];
    // Każdy niepewny sygnał to Unknown, więc wystarczy jedno ustalenie listy (git jest tu najdroższy).
    $detected = scopeGatesOf(scopeResolve($repo, CommitMode::Unknown));
    foreach ($degraded as $label => $argv) {
        expect((new CommitModeDetector())->detect($argv))->toBe(CommitMode::Unknown, $label);
        foreach ($actual as $gates) {
            expect(scopeIsSubset($gates, $detected))->toBeTrue(sprintf('%s: %s nie zawiera %s', $label, implode(',', $detected), implode(',', $gates)));
        }
    }
    $repo->remove();
})->with(array_keys(scopeInvariantStates()));

it('zakres od bazy (CI, informacyjnie): lista zmian od rewizji do HEAD, a zła baza to pełny zestaw', function (): void {
    $repo = scopeRepoWithCode();
    $base = $repo->git('rev-parse', 'HEAD~1');
    $resolver = new ScopeResolver(new ProcessGitRunner($repo->path));

    $range = $resolver->range($base);

    expect($range->paths)->toBe(['src/Kod.php'])->and($range->unresolvedReason)->toBeNull();
    foreach (['', '-p', 'nie-ma-takiej-rewizji'] as $bad) {
        expect($resolver->range($bad)->unresolvedReason)->not->toBeNull();
    }
    $repo->remove();
});
