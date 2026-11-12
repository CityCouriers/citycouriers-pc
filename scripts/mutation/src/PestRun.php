<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Dev\Mutation;

use RuntimeException;

/**
 * Uruchomienie procesu (Pest, git) z jawnym stdin (pusta rura zamknięta od razu). Wyjście trafia na bieżąco do logu
 * (i opcjonalnie na ekran); stderr idzie do pliku obok i jest dopisywany na końcu logu (na Windows nie da się czekać
 * na dwie rury naraz).
 */
final class PestRun
{
    /**
     * @param list<string> $command
     * @param array<string, string> $env zmienne dopisane do środowiska bieżącego procesu
     *
     * @return ?int kod wyjścia albo null, gdy proces został przerwany sygnałem
     */
    public static function run(array $command, string $cwd, array $env, string $logPath, bool $echo): ?int
    {
        $log = fopen($logPath, 'wb');
        if ($log === false) {
            throw new RuntimeException('Nie można zapisać logu: ' . $logPath);
        }
        $errorPath = $logPath . '.stderr';
        // Zmienna o nazwie z samych cyfr trafia do tablicy PHP jako klucz int; `proc_open` nie potrafi jej przekazać (#69).
        $environment = $env + array_filter(getenv(), is_string(...), ARRAY_FILTER_USE_KEY);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errorPath, 'w']], $pipes, $cwd, $environment);
        if (!is_resource($process)) {
            fclose($log);

            throw new RuntimeException('Nie można uruchomić procesu: ' . implode(' ', $command));
        }
        fclose($pipes[0]);
        self::copy($pipes[1], $log, $echo);
        fclose($pipes[1]);
        $code = proc_close($process);
        $errors = (string) @file_get_contents($errorPath);
        @unlink($errorPath);
        self::write($log, $errors, $echo);
        fclose($log);

        return $code < 0 ? null : $code;
    }

    /**
     * Wyjście procesu jako tekst (dla krótkich poleceń, np. git).
     *
     * @param list<string> $command
     *
     * @return array{?int, string}
     */
    public static function capture(array $command, string $cwd): array
    {
        $path = tempnam(sys_get_temp_dir(), 'pc-mutation-');
        if ($path === false) {
            throw new RuntimeException('Nie można utworzyć pliku tymczasowego.');
        }
        try {
            $code = self::run($command, $cwd, [], $path, false);

            return [$code, (string) file_get_contents($path)];
        } finally {
            unlink($path);
        }
    }

    /**
     * @param resource $from
     * @param resource $log
     */
    private static function copy($from, $log, bool $echo): void
    {
        while (!feof($from)) {
            $chunk = fread($from, 8192);
            if ($chunk === false) {
                return;
            }
            self::write($log, $chunk, $echo);
        }
    }

    /**
     * @param resource $log
     */
    private static function write($log, string $text, bool $echo): void
    {
        fwrite($log, $text);
        if ($echo) {
            fwrite(STDOUT, $text);
        }
    }
}
