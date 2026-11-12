<?php

declare(strict_types=1);

/**
 * Polityka procesów potomnych: żaden proces uruchamiany przez testy ani przez kod pakietu nie dziedziczy stdin Pesta.
 * Msys bash z odziedziczonym pty mintty urywa bieg Pesta z kodem 0 (zob. Tests\Support\Child), a CI na Linuksie i konsole
 * PowerShell/cmd tego nie pokazują, więc pilnujemy tego statycznie.
 *
 * @param string $source kod PHP
 *
 * @return list<string> naruszenia (puste, gdy kod jest zgodny)
 */
function childProcessViolations(string $source, bool $allowOpenHelper = false): array
{
    $found = [];
    $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*|#[^\n]*~s', '', $source);
    if (!$allowOpenHelper && preg_match_all('~proc_open\s*\((?:(?!;\s*\n).)*~s', $code, $calls) > 0) {
        foreach ($calls[0] as $call) {
            if (preg_match('~\b0\s*=>~', $call) !== 1) {
                $found[] = 'proc_open bez deskryptora 0 (stdin)';
            }
        }
    }
    // Napisy w cudzysłowach nie są wywołaniami, więc na czas tego sprawdzenia są puste.
    $bare = (string) preg_replace('~\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"~s', "''", $code);
    if (preg_match('~(?<![\w>:$])(shell_exec|exec|passthru|system|popen|pcntl_exec)\s*\(~', $bare) === 1) {
        $found[] = 'powłoka bez kontroli stdin (shell_exec, exec, passthru, system, popen)';
    }

    return $found;
}

/**
 * @return list<string>
 */
function childProcessFiles(string $directory): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php' && !str_contains(str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname()), '/Fixtures/')) {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

it('każdy proc_open w src i tests ma jawny stdin, a testy nie używają shell_exec ani exec', function (): void {
    $root = dirname(__DIR__, 2);
    $violations = [];
    foreach ([...childProcessFiles($root . '/src'), ...childProcessFiles($root . '/tests')] as $path) {
        $isHelper = str_ends_with(str_replace(DIRECTORY_SEPARATOR, '/', $path), 'tests/Support/Child.php');
        $isPolicy = str_ends_with(str_replace(DIRECTORY_SEPARATOR, '/', $path), 'tests/Unit/ChildProcessPolicyTest.php');
        if ($isPolicy) {
            continue;
        }
        foreach (childProcessViolations((string) file_get_contents($path), $isHelper) as $violation) {
            $violations[] = substr($path, strlen($root) + 1) . ': ' . $violation;
        }
    }

    expect($violations)->toBe([]);
});

it('kontrola ujemna: skaner wykrywa proc_open bez stdin i powłokę bez kontroli', function (string $source, int $count): void {
    expect(count(childProcessViolations($source)))->toBe($count);
})->with([
    'proc_open tylko ze stdout i stderr' => ["<?php\n\$p = proc_open(\$c, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], \$pipes);\n", 1],
    'proc_open z deskryptorem 0' => ["<?php\n\$p = proc_open(\$c, [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w']], \$pipes);\n", 0],
    'shell_exec' => ["<?php\n\$x = shell_exec('git status');\n", 1],
    'exec' => ["<?php\nexec('git status', \$o);\n", 1],
    'komentarz nie liczy się' => ["<?php\n// proc_open(\$c, [1 => 1]) i shell_exec('x')\n", 0],
]);
