<?php

declare(strict_types=1);

use CityCouriers\Pc\Application;
use CityCouriers\Pc\Engine\Checked;
use CityCouriers\Pc\Engine\CheckedUnit;
use CityCouriers\Pc\Engine\GateContext;
use CityCouriers\Pc\Engine\GateRegistry;
use CityCouriers\Pc\Engine\GateResult;
use CityCouriers\Pc\Engine\ProcessGit;
use CityCouriers\Pc\Engine\ResultFactory;
use CityCouriers\Pc\Engine\RunCommandFactory;
use CityCouriers\Pc\Tests\Support\FakeGate;
use CityCouriers\Pc\Tests\Support\Harness;

/**
 * Tymczasowe repozytorium git z jednym commitem. Zwraca ścieżkę katalogu.
 */
function gitSandbox(): string
{
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pcgit' . bin2hex(random_bytes(4));
    mkdir($directory);
    sandboxGit($directory, 'init', '--quiet');
    file_put_contents($directory . '/a.txt', "a\n");
    sandboxGit($directory, 'add', 'a.txt');
    sandboxGit($directory, '-c', 'user.name=Test', '-c', 'user.email=pc-test', 'commit', '--quiet', '-m', 'start');

    return $directory;
}

function sandboxGit(string $directory, string ...$arguments): string
{
    $process = proc_open(['git', '-C', $directory, ...array_values($arguments)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if ($process === false) {
        throw new RuntimeException('Nie udało się uruchomić gita.');
    }
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return trim($output);
}

function removeSandbox(string $directory): void
{
    foreach (array_diff((array) scandir($directory), ['.', '..']) as $name) {
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        chmod($path, 0o700);
        is_dir($path) ? removeSandbox($path) : unlink($path);
    }
    rmdir($directory);
}

it('prawdziwy git: --staged przekazuje pliki ze stage\'a, --base daje pełny OID commita', function (): void {
    $repo = gitSandbox();
    file_put_contents($repo . '/b.php', "<?php\n");
    sandboxGit($repo, 'add', 'b.php');
    $head = sandboxGit($repo, 'rev-parse', 'HEAD');

    $seen = [];
    $probe = (new FakeGate('format'))->does(static function (GateContext $context) use (&$seen): GateResult {
        $seen = ['files' => $context->files, 'base' => $context->diffBase];

        return (new ResultFactory())->passed(new Checked(CheckedUnit::Checks, 1));
    });
    $config = Harness::config(['format']);
    $application = new Application(new RunCommandFactory(registry: new GateRegistry($probe), root: $repo));

    $staged = $application->run(['run', '--staged', '--format=json', '--config=' . $config]);
    $stagedSeen = $seen;
    $based = $application->run(['run', '--base=HEAD', '--format=json', '--config=' . $config]);
    $invalid = $application->run(['run', '--base=nie-ma-takiej-rewizji', '--format=json', '--config=' . $config]);
    $injection = $application->run(['run', '--base=--output=x', '--format=json', '--config=' . $config]);
    unlink($config);
    removeSandbox($repo);

    expect($staged->exitCode)->toBe(0)
        ->and($stagedSeen)->toBe(['files' => ['b.php'], 'base' => null])
        ->and($based->exitCode)->toBe(0)
        ->and($seen)->toBe(['files' => null, 'base' => $head])
        ->and(strlen($head))->toBeGreaterThanOrEqual(40)
        ->and($invalid->exitCode)->toBe(2)
        ->and($injection->exitCode)->toBe(2);
})->skip(fn(): bool => !is_string(shell_exec('git --version 2>&1')) || !str_contains((string) shell_exec('git --version 2>&1'), 'git version'), 'git niedostępny');

it('prawdziwy git: --staged poza repozytorium to kod 2', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pcnogit' . bin2hex(random_bytes(4));
    mkdir($directory);
    $config = Harness::config(['format']);
    $result = (new Application(new RunCommandFactory(registry: new GateRegistry(new FakeGate('format')), root: $directory)))
        ->run(['run', '--staged', '--format=json', '--config=' . $config]);
    unlink($config);
    rmdir($directory);

    expect($result->exitCode)->toBe(2);
})->skip(fn(): bool => (new ProcessGit(sys_get_temp_dir()))->isRepository(), 'Katalog tymczasowy leży w repozytorium git.');
