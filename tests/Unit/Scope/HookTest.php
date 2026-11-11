<?php

declare(strict_types=1);

use CityCouriers\Pc\Tests\Support\GitRepo;
use CityCouriers\Pc\Tests\Support\ScopeSupport;

/**
 * Test prawdziwego hooka `.githooks/pre-commit` w tymczasowym repo: prawdziwy `git commit`, prawdziwy klasyfikator
 * (`php bin/pc scope`), atrapy `composer`, `npm`, `node` i `scripts/ci/github-gate.sh`, które zapisują nazwy uruchomionych bramek.
 * Na Windows Git for Windows uruchamia hook poza drzewem procesów MSYS, więc tryb commita jest tam nieustalony (baza HEAD^):
 * oczekiwania bez dodatniego sygnału są wtedy szersze, ale niezmiennik (nigdy węższy zbiór) obowiązuje wszędzie.
 */
const HOOK_PHP_GATES = ['lint', 'stan', 'smells', 'test', 'negative'];

/**
 * @return array{GitRepo, string} repo z hookiem i katalog z atrapami narzędzi
 */
function hookRepo(bool $withHistory = true, bool $node = true, bool $npm = true): array
{
    $repo = new GitRepo();
    $root = ScopeSupport::root();
    $fake = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pchookbin' . bin2hex(random_bytes(6));
    mkdir($fake);
    $log = $fake . DIRECTORY_SEPARATOR . 'log.txt';
    touch($log);

    foreach (['.githooks/pre-commit', 'scripts/quality/scope-rules.json', 'bin/pc'] as $file) {
        $repo->write($file, (string) file_get_contents($root . '/' . $file));
    }
    $repo->write('vendor/autoload.php', "<?php\n\nrequire " . var_export($root . '/vendor/autoload.php', true) . ";\n");
    $repo->write('scripts/ci/github-gate.sh', "#!/bin/sh\necho \"gate \$1\" >> \"\$PC_TEST_LOG\"\n");
    chmod($repo->path . '/.githooks/pre-commit', 0o755);
    $repo->git('config', 'core.hooksPath', '.githooks');

    $tools = ['composer' => true, 'node' => $node, 'npm' => $npm];
    foreach ($tools as $name => $enabled) {
        if ($enabled) {
            file_put_contents($fake . DIRECTORY_SEPARATOR . $name, "#!/bin/sh\necho \"" . $name . " \$*\" >> \"\$PC_TEST_LOG\"\n");
            chmod($fake . DIRECTORY_SEPARATOR . $name, 0o755);
        }
    }
    $repo->extraEnvironment = ['PC_TEST_LOG' => $log, 'PATH' => hookPath($fake)];

    if ($withHistory) {
        $repo->git('add', '.githooks', 'scripts', 'bin', 'vendor');
        $repo->git('commit', '--quiet', '--no-verify', '-m', 'setup');
        $repo->write('src/Kod.php', "<?php\n");
        $repo->stage('src/Kod.php');
        $repo->git('commit', '--quiet', '--no-verify', '-m', 'kod');
    }

    return [$repo, $fake];
}

/**
 * PATH bez prawdziwego Node i npm: na Windows to PATH bez katalogów z `node`/`npm`, gdzie indziej katalog z dowiązaniami
 * tylko do potrzebnych programów (Node bywa w tym samym katalogu co git).
 */
function hookPath(string $fake): string
{
    $entries = explode(PATH_SEPARATOR, (string) getenv('PATH'));
    if (PHP_OS_FAMILY === 'Windows') {
        $clean = array_filter($entries, static function (string $dir): bool {
            foreach (['node', 'node.exe', 'npm', 'npm.cmd'] as $name) {
                if ($dir !== '' && is_file($dir . DIRECTORY_SEPARATOR . $name)) {
                    return false;
                }
            }

            return true;
        });

        return implode(PATH_SEPARATOR, [$fake, dirname(PHP_BINARY), ...$clean]);
    }
    $tools = $fake . DIRECTORY_SEPARATOR . 'tools';
    mkdir($tools);
    foreach (['env', 'bash', 'sh', 'git', 'php', 'ps', 'cat', 'tr', 'dirname', 'basename', 'rm', 'mkdir'] as $name) {
        foreach ($entries as $dir) {
            $candidate = $dir . DIRECTORY_SEPARATOR . $name;
            if ($dir !== '' && is_file($candidate) && is_executable($candidate)) {
                symlink($candidate, $tools . DIRECTORY_SEPARATOR . $name);
                break;
            }
        }
    }

    return $fake . PATH_SEPARATOR . $tools;
}

/**
 * Bramki uruchomione przez hook, w kolejności z dziennika atrap.
 *
 * @return list<string>
 */
function hookRan(string $fake): array
{
    $map = ['composer privacy' => 'privacy', 'composer lint' => 'lint', 'composer stan' => 'stan', 'composer smells' => 'smells', 'composer test' => 'test', 'composer negative' => 'negative', 'npm test --silent' => 'process'];
    $gates = [];
    foreach (array_filter(explode("\n", str_replace("\r", '', (string) file_get_contents($fake . DIRECTORY_SEPARATOR . 'log.txt')))) as $line) {
        $gates[] = str_starts_with($line, 'gate ') ? substr($line, 5) : ($map[$line] ?? (str_starts_with($line, 'node --test') ? 'process' : 'nieznana: ' . $line));
    }

    return $gates;
}

/**
 * Skrypt `test` z package.json (hook bez npm woła ten sam glob przez `node --test`).
 */
function hookNpmTestScript(): string
{
    $package = json_decode((string) file_get_contents(ScopeSupport::root() . '/package.json'), true, 512, JSON_THROW_ON_ERROR);
    $scripts = is_array($package) ? ($package['scripts'] ?? null) : null;
    $script = is_array($scripts) ? ($scripts['test'] ?? null) : null;

    return is_string($script) ? $script : '';
}

/**
 * @param list<string> $arguments
 *
 * @return array{int, string, list<string>} kod, wyjście hooka i uruchomione bramki
 */
function hookCommit(GitRepo $repo, string $fake, array $arguments): array
{
    file_put_contents($fake . DIRECTORY_SEPARATOR . 'log.txt', '');
    [$code, $output] = $repo->run(['commit', '--quiet', ...$arguments]);

    return [$code, $output, hookRan($fake)];
}

function hookCleanup(GitRepo $repo, string $fake): void
{
    $repo->remove();
    foreach (array_diff((array) scandir($fake), ['.', '..']) as $name) {
        $path = $fake . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff((array) scandir($path), ['.', '..']) as $link) {
                unlink($path . DIRECTORY_SEPARATOR . $link);
            }
            rmdir($path);
            continue;
        }
        unlink($path);
    }
    rmdir($fake);
}

/**
 * @param list<string> $ran
 */
function hookIsFull(array $ran): bool
{
    return array_diff(ScopeSupport::rules()->gates, $ran) === [];
}

/**
 * Sygnał trybu commita jest ustalany z /proc albo `ps`; na Windows go nie ma (baza HEAD^).
 */
function hookHasSignal(): bool
{
    return PHP_OS_FAMILY !== 'Windows';
}

it('hook: zwykły commit z samym .md po commicie z kodem (potwierdzony tryb) uruchamia tylko privacy; bez sygnału bramki PHP, nigdy mniej', function (): void {
    [$repo, $fake] = hookRepo();
    $repo->write('docs/a.md', "a\n");
    $repo->stage('docs/a.md');

    [$code, $output, $ran] = hookCommit($repo, $fake, ['-m', 'docs']);

    expect($code)->toBe(0, $output);
    if (hookHasSignal()) {
        expect($ran)->toBe(['privacy'])->and($output)->toContain('pominięte');
    } else {
        expect($ran)->toContain('privacy', ...HOOK_PHP_GATES);
    }
    expect($output)->toContain('[pre-commit] OK');
    hookCleanup($repo, $fake);
});

it('hook: zwykły pusty stage (--allow-empty) uruchamia pełny zestaw (negatyw)', function (): void {
    [$repo, $fake] = hookRepo();

    [$code, $output, $ran] = hookCommit($repo, $fake, ['--allow-empty', '-m', 'pusty']);

    expect($code)->toBe(0, $output)->and(hookIsFull($ran))->toBeTrue();
    hookCleanup($repo, $fake);
});

it('hook: amend bez nowych zmian i amend z samym .md na HEAD z kodem: bramki PHP biegną (negatyw)', function (array $arguments, bool $stageDocs): void {
    [$repo, $fake] = hookRepo();
    if ($stageDocs) {
        $repo->write('docs/a.md', "a\n");
        $repo->stage('docs/a.md');
    }

    [$code, $output, $ran] = hookCommit($repo, $fake, ScopeSupport::strings($arguments));

    expect($code)->toBe(0, $output)->and($ran)->toContain(...HOOK_PHP_GATES)->toContain('privacy');
    hookCleanup($repo, $fake);
})->with([
    'amend --no-edit bez zmian' => [['--amend', '--no-edit'], false],
    'amend z samym .md' => [['--amend', '--no-edit'], true],
    'skrócona opcja --amen z samym .md' => [['--amen', '--no-edit'], true],
]);

it('hook: alias gita (git -c ... commit z --amend przez inny proces nadrzędny) nie zawęża zbioru: baza HEAD^', function (): void {
    [$repo, $fake] = hookRepo();
    $repo->git('config', 'alias.ci', 'commit');
    $repo->write('docs/a.md', "a\n");
    $repo->stage('docs/a.md');
    file_put_contents($fake . DIRECTORY_SEPARATOR . 'log.txt', '');

    [$code, $output] = $repo->run(['ci', '--amend', '--no-edit', '--quiet']);

    expect($code)->toBe(0, $output)->and(hookRan($fake))->toContain(...HOOK_PHP_GATES);
    hookCleanup($repo, $fake);
});

it('hook: pierwszy commit bez błędu (baza puste drzewo), pusty stage daje pełny zestaw, amend korzenia też nie zwalnia bramek', function (): void {
    [$repo, $fake] = hookRepo(false);
    $repo->write('README.md', "r\n");
    $repo->stage('README.md');

    [$code, $output, $ran] = hookCommit($repo, $fake, ['-m', 'pierwszy']);
    expect($code)->toBe(0, $output)->and($ran)->toBe(['privacy']);

    [$code, $output, $ran] = hookCommit($repo, $fake, ['--amend', '--no-edit']);
    expect($code)->toBe(0, $output)->and(hookIsFull($ran))->toBeTrue();
    hookCleanup($repo, $fake);

    [$empty, $fakeEmpty] = hookRepo(false);
    [$code, $output, $ran] = hookCommit($empty, $fakeEmpty, ['--allow-empty', '-m', 'pusty']);
    expect($code)->toBe(0, $output)->and(hookIsFull($ran))->toBeTrue();
    hookCleanup($empty, $fakeEmpty);
});

it('hook: amend korzenia z kodem PHP bez nowych zmian uruchamia bramki PHP', function (): void {
    [$repo, $fake] = hookRepo(false);
    $repo->write('src/Kod.php', "<?php\n");
    $repo->stage('src/Kod.php');
    $repo->git('commit', '--quiet', '--no-verify', '-m', 'korzen');

    [$code, $output, $ran] = hookCommit($repo, $fake, ['--amend', '--no-edit']);

    expect($code)->toBe(0, $output)->and($ran)->toContain(...HOOK_PHP_GATES);
    hookCleanup($repo, $fake);
});

it('hook: commit rozwiązujący merge albo cherry-pick uruchamia pełny zestaw', function (string $operation): void {
    [$repo, $fake] = hookRepo();
    $repo->write('f.txt', "a\n");
    $repo->stage('f.txt');
    $repo->git('commit', '--quiet', '--no-verify', '-m', 'f');
    $repo->git('switch', '--quiet', '-c', 'b');
    $repo->write('f.txt', "b\n");
    $repo->stage('f.txt');
    $repo->git('commit', '--quiet', '--no-verify', '-m', 'na-b');
    $repo->git('switch', '--quiet', 'main');
    $repo->write('f.txt', "c\n");
    $repo->stage('f.txt');
    $repo->git('commit', '--quiet', '--no-verify', '-m', 'na-main');
    $repo->run([$operation === 'merge' ? 'merge' : 'cherry-pick', 'b']);
    $repo->write('f.txt', "rozwiazane\n");
    $repo->stage('f.txt');

    [$code, $output, $ran] = hookCommit($repo, $fake, ['--no-edit']);

    expect($code)->toBe(0, $output)->and(hookIsFull($ran))->toBeTrue();
    hookCleanup($repo, $fake);
})->with(['merge', 'cherry-pick']);

it('hook: repozytorium płytkie uruchamia pełny zestaw', function (): void {
    [$origin, $fakeOrigin] = hookRepo();
    $location = 'file://' . (str_starts_with($origin->path, '/') ? '' : '/') . str_replace('\\', '/', $origin->path);
    $target = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pcscope' . bin2hex(random_bytes(6));
    $origin->git('clone', '--quiet', '--depth', '1', $location, $target);
    $clone = new GitRepo($target);
    $clone->extraEnvironment = $origin->extraEnvironment;
    $clone->git('config', 'core.hooksPath', '.githooks');
    $clone->write('docs/a.md', "a\n");
    $clone->stage('docs/a.md');

    [$code, $output, $ran] = hookCommit($clone, $fakeOrigin, ['-m', 'docs']);

    expect($code)->toBe(0, $output)->and(hookIsFull($ran))->toBeTrue();
    $clone->remove();
    hookCleanup($origin, $fakeOrigin);
});

it('hook: detached HEAD daje ten sam wynik co gałąź', function (): void {
    [$repo, $fake] = hookRepo();
    $repo->git('checkout', '--quiet', '--detach');
    $repo->write('docs/a.md', "a\n");
    $repo->stage('docs/a.md');

    [$code, $output, $ran] = hookCommit($repo, $fake, ['-m', 'docs']);

    expect($code)->toBe(0, $output);
    hookHasSignal() ? expect($ran)->toBe(['privacy']) : expect($ran)->toContain('privacy', ...HOOK_PHP_GATES);
    hookCleanup($repo, $fake);
});

it('hook: PC_HOOK_SCOPE=all wymusza pełny zestaw dla samego .md, a inna wartość niczego nie wyłącza', function (string $value, bool $full): void {
    [$repo, $fake] = hookRepo();
    $repo->extraEnvironment['PC_HOOK_SCOPE'] = $value;
    $repo->write('docs/a.md', "a\n");
    $repo->stage('docs/a.md');

    [$code, $output, $ran] = hookCommit($repo, $fake, ['-m', 'docs']);

    expect($code)->toBe(0, $output)->and($ran)->toContain('privacy');
    if ($full) {
        expect(hookIsFull($ran))->toBeTrue();
    } elseif (hookHasSignal()) {
        expect($ran)->toBe(['privacy']);
    }
    hookCleanup($repo, $fake);
})->with([['all', true], ['none', false]]);

it('hook bez Node: bramka process to „nie przeskanowano (brak node)”, nie przeszła, a commit nie jest blokowany', function (): void {
    [$repo, $fake] = hookRepo(false, false, false);
    $repo->write('test/x.test.js', "// test\n");
    $repo->stage('test/x.test.js');

    [$code, $output, $ran] = hookCommit($repo, $fake, ['-m', 'js']);

    expect($code)->toBe(0, $output)
        ->and($ran)->toBe(['privacy'])
        ->and($output)->toContain('process: nie przeskanowano (brak node)')
        ->and($output)->toContain('NIEPEŁNY, nie przeskanowano: process')
        ->and($output)->not->toContain('[pre-commit] OK')
        ->and($output)->toContain('przeszły: privacy');
    expect($repo->git('rev-list', '--count', 'HEAD'))->toBe('1');
    hookCleanup($repo, $fake);
});

it('hook z Node, ale bez npm: process biegnie przez node --test z tym samym zestawem plików co npm test', function (): void {
    [$repo, $fake] = hookRepo(false, true, false);
    $repo->write('test/x.test.js', "// test\n");
    $repo->stage('test/x.test.js');

    [$code, $output] = hookCommit($repo, $fake, ['-m', 'js']);
    $log = (string) file_get_contents($fake . DIRECTORY_SEPARATOR . 'log.txt');
    preg_match('~node --test "([^"]+)"~', hookNpmTestScript(), $glob);

    expect($code)->toBe(0, $output)
        ->and($output)->toContain('brak npm')
        ->and($log)->toContain('node --test ' . ($glob[1] ?? 'brak'))
        ->and($log)->not->toContain('npm ')
        ->and($output)->toContain('[pre-commit] OK');
    hookCleanup($repo, $fake);
});

it('hook z Node i npm: process biegnie przez npm test', function (): void {
    [$repo, $fake] = hookRepo(false);
    $repo->write('test/x.test.js', "// test\n");
    $repo->stage('test/x.test.js');

    [$code, $output, $ran] = hookCommit($repo, $fake, ['-m', 'js']);

    expect($code)->toBe(0, $output)->and($ran)->toBe(['privacy', 'process'])->and((string) file_get_contents($fake . DIRECTORY_SEPARATOR . 'log.txt'))->toContain('npm test --silent');
    hookCleanup($repo, $fake);
});

it('hook: awaria klasyfikatora (zepsute reguły, kod ≠ 0) uruchamia pełny zestaw z komunikatem', function (): void {
    [$repo, $fake] = hookRepo();
    $repo->write('scripts/quality/scope-rules.json', '{');
    $repo->write('docs/a.md', "a\n");
    $repo->stage('docs/a.md');

    [$code, $output, $ran] = hookCommit($repo, $fake, ['-m', 'docs']);

    expect($code)->toBe(0, $output)->and(hookIsFull($ran))->toBeTrue()->and($output)->toContain('klasyfikator zakresu zawiódł');
    hookCleanup($repo, $fake);
});

it('hook: klasyfikator, który zwraca śmieci albo nieznaną bramkę, nie zwalnia żadnej bramki', function (string $answer): void {
    [$repo, $fake] = hookRepo();
    $php = $fake . DIRECTORY_SEPARATOR . 'php';
    file_put_contents($php, "#!/bin/sh\necho \"" . $answer . "\"\n");
    chmod($php, 0o755);
    $repo->write('docs/a.md', "a\n");
    $repo->stage('docs/a.md');

    [$code, $output, $ran] = hookCommit($repo, $fake, ['-m', 'docs']);

    expect($code)->toBe(0, $output)->and(hookIsFull($ran))->toBeTrue()->and($output)->toContain('klasyfikator zakresu zawiódł');
    hookCleanup($repo, $fake);
})->with(['nieznana bramka' => ['privacy bogus'], 'pusta odpowiedź' => ['']]);

it('hook: bramka, która pada, zatrzymuje commit (kod ≠ 0, brak komunikatu OK)', function (): void {
    [$repo, $fake] = hookRepo();
    $composer = $fake . DIRECTORY_SEPARATOR . 'composer';
    file_put_contents($composer, "#!/bin/sh\necho \"composer \$*\" >> \"\$PC_TEST_LOG\"\nexit 1\n");
    chmod($composer, 0o755);
    $repo->write('docs/a.md', "a\n");
    $repo->stage('docs/a.md');

    [$code, $output] = hookCommit($repo, $fake, ['-m', 'docs']);

    expect($code)->not->toBe(0)->and($output)->not->toContain('[pre-commit] OK')->and($repo->git('rev-list', '--count', 'HEAD'))->toBe('2');
    hookCleanup($repo, $fake);
});

it('zgodność hooka z regułami: lista bramek, obsługa każdej bramki i ten sam zestaw plików testów co npm test', function (): void {
    $hook = (string) file_get_contents(ScopeSupport::root() . '/.githooks/pre-commit');
    preg_match('~^all_gates="([^"]+)"~m', $hook, $all);
    preg_match_all('~^        ([a-z]+)\)~m', $hook, $handled);
    preg_match('~node --test "([^"]+)"~', hookNpmTestScript(), $glob);

    expect(explode(' ', $all[1] ?? ''))->toBe(ScopeSupport::rules()->gates);
    $gates = ScopeSupport::rules()->gates;
    sort($gates);
    $names = $handled[1];
    sort($names);
    expect($names)->toBe($gates)
        ->and($glob[1] ?? '')->not->toBe('')
        ->and($hook)->toContain('node --test "' . ($glob[1] ?? '') . '"');
});
