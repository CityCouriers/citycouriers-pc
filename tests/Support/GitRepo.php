<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Tests\Support;

use RuntimeException;

/**
 * Tymczasowe, prawdziwe repozytorium git do testów zakresu (#40). Środowisko jest oczyszczone ze zmiennych `GIT_*`
 * (test może biec z hooka, który ustawia `GIT_INDEX_FILE`), tożsamość autora jest stała, podpisywanie wyłączone.
 */
final class GitRepo
{
    public readonly string $path;

    /** @var array<string, string> dodatkowe zmienne środowiska dla gita (nadpisują odziedziczone) */
    public array $extraEnvironment = [];

    public function __construct(?string $path = null)
    {
        self::scrubProcessEnvironment();
        $this->path = $path ?? $this->makeDirectory();
        if ($path === null) {
            $this->git('init', '--quiet', '--initial-branch=main', '--template=');
        }
    }

    public function git(string ...$arguments): string
    {
        return $this->gitWithInput(null, ...$arguments);
    }

    public function gitWithInput(?string $input, string ...$arguments): string
    {
        [$code, $output] = $this->run(array_values($arguments), null, $input);
        if ($code !== 0) {
            throw new RuntimeException(sprintf('git %s zakończył się kodem %d: %s', implode(' ', $arguments), $code, $output));
        }

        return trim($output);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string} kod wyjścia i połączone stdout oraz stderr
     */
    public function run(array $arguments, ?string $directory = null, ?string $input = null): array
    {
        $command = ['git', '-C', $directory ?? $this->path, '-c', 'commit.gpgsign=false', '-c', 'core.autocrlf=false', '-c', 'protocol.file.allow=always', ...$arguments];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $this->environment());
        if ($process === false) {
            throw new RuntimeException('Nie udało się uruchomić gita.');
        }
        fwrite($pipes[0], (string) $input);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    /**
     * Buduje historię jednym wywołaniem `git fast-import`, potem wypełnia indeks i katalog roboczy (szybciej niż
     * osobne `add` i `commit`, a git jest tu najdroższą operacją).
     *
     * @param list<array<string, string>> $commits pliki kolejnych commitów (ścieżka => treść)
     */
    public function history(array $commits): void
    {
        $stream = '';
        foreach ($commits as $number => $files) {
            $message = 'c' . $number;
            $stream .= "commit refs/heads/main
committer Test <test@example.com> " . (1_700_000_000 + $number) . " +0000
data " . strlen($message) . "
" . $message . "
";
            foreach ($files as $file => $content) {
                $stream .= 'M 100644 inline ' . $file . "
data " . strlen($content) . "
" . $content . "
";
            }
        }
        $this->gitWithInput($stream, 'fast-import', '--quiet');
        $this->git('reset', '--quiet', '--hard', 'main');
    }

    public function write(string $file, string $content = "x\n"): void
    {
        $target = $this->path . '/' . $file;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0o777, true);
        }
        file_put_contents($target, $content);
    }

    public function stage(string ...$files): void
    {
        $this->git('add', '--', ...$files);
    }

    /**
     * Zapisuje pliki i zatwierdza je (bez hooków): zwraca OID commita.
     *
     * @param array<string, string> $files
     */
    public function commit(array $files, string $message = 'c'): string
    {
        foreach ($files as $file => $content) {
            $this->write($file, $content);
            $this->stage($file);
        }
        $this->git('commit', '--quiet', '--no-verify', '--allow-empty', '-m', $message);

        return $this->git('rev-parse', 'HEAD');
    }

    /**
     * @return array<string, string>
     */
    public function environment(): array
    {
        $environment = [];
        foreach (getenv() as $name => $value) {
            if (self::isolated($name)) {
                continue;
            }
            if (!str_starts_with($name, 'GIT_') || $name === 'GIT_EXEC_PATH') {
                $environment[$name] = $value;
            }
        }

        return $this->extraEnvironment + $environment + [
            'GIT_AUTHOR_NAME' => 'Test',
            'GIT_AUTHOR_EMAIL' => 'test@example.com',
            'GIT_COMMITTER_NAME' => 'Test',
            'GIT_COMMITTER_EMAIL' => 'test@example.com',
            'GIT_CONFIG_NOSYSTEM' => '1',
        ];
    }

    /**
     * Testy mogą biec z hooka `pre-commit` (`composer check`), który ustawia `GIT_DIR`, `GIT_INDEX_FILE` i podobne.
     * Kod pod testem (`ProcessGit`, `ProcessGitRunner`) celowo dziedziczy środowisko procesu, więc bez tego
     * zapytałby repozytorium hostującego, nie testowe. Usuwamy te zmienne z procesu testowego.
     */
    public static function scrubProcessEnvironment(): void
    {
        foreach (array_keys(getenv()) as $name) {
            if (self::isolated((string) $name) || (str_starts_with((string) $name, 'GIT_') && $name !== 'GIT_EXEC_PATH')) {
                putenv((string) $name);
            }
        }
    }

    /**
     * Zmienne sterujące hookiem i testami nie mogą przeciekać z otoczenia do repozytorium testowego
     * (np. wyeksportowane `PC_HOOK_SCOPE=all` zamieniałoby każdy test zakresu w pełny zestaw).
     */
    private static function isolated(string $name): bool
    {
        return str_starts_with($name, 'PC_HOOK_') || str_starts_with($name, 'PC_TEST_');
    }

    public function remove(): void
    {
        $this->delete($this->path);
    }

    private function makeDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pcscope' . bin2hex(random_bytes(6));
        mkdir($directory);

        return $directory;
    }

    private function delete(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (array_diff((array) scandir($directory), ['.', '..']) as $name) {
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_dir($path) && !is_link($path)) {
                $this->delete($path);
                continue;
            }
            chmod($path, 0o700);
            unlink($path);
        }
        rmdir($directory);
    }
}
