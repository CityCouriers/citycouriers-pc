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
    // Tokenizer, nie regex: komentarze i napisy (też z `/*` albo nazwą funkcji w środku) nie mylą skanera.
    $tokens = array_values(array_filter(
        token_get_all($source),
        static fn(array|string $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
    $found = [];
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_STRING || ($tokens[$index + 1] ?? null) !== '(') {
            continue;
        }
        $previous = $tokens[$index - 1] ?? null;
        $isCall = !is_array($previous) || !in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true);
        $name = strtolower($token[1]);
        if ($isCall && in_array($name, ['shell_exec', 'exec', 'passthru', 'system', 'popen', 'pcntl_exec'], true)) {
            $found[] = 'powłoka bez kontroli stdin (shell_exec, exec, passthru, system, popen)';
        }
        if ($isCall && $name === 'proc_open' && !$allowOpenHelper && !childProcessHasStdin($tokens, $index + 1)) {
            $found[] = 'proc_open bez deskryptora 0 (stdin)';
        }
    }

    return $found;
}

/**
 * Czy w nawiasach wywołania (od otwierającego) jest klucz `0 =>` albo deskryptor przekazany jako wartość z kluczem 0.
 *
 * @param list<array{int, string, int}|string> $tokens
 */
function childProcessHasStdin(array $tokens, int $open): bool
{
    $depth = 0;
    for ($i = $open; $i < count($tokens); $i++) {
        $token = $tokens[$i];
        $depth += $token === '(' || $token === '[' ? 1 : 0;
        $depth -= $token === ')' || $token === ']' ? 1 : 0;
        if (is_array($token) && $token[0] === T_LNUMBER && $token[1] === '0' && ($tokens[$i + 1] ?? null) !== null && is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_DOUBLE_ARROW) {
            return true;
        }
        if ($depth === 0) {
            break;
        }
    }

    return false;
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
