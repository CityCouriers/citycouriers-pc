<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Tests\Support;

use CityCouriers\Pc\Process\EmptyInput;

/**
 * Jedyne miejsce, w którym testy uruchamiają procesy potomne. Każdy dostaje stdin z pustego urządzenia, więc nie
 * dziedziczy terminala Pesta. Msys bash (Git for Windows) z odziedziczonym pty mintty zmienia tryb terminala i urywa bieg
 * procesu nadrzędnego z kodem 0, a w PowerShell i cmd (stdin to konsola Windows) tego nie widać. Pilnuje tego
 * `Tests\Unit\ChildProcessPolicyTest`.
 */
final class Child
{
    /**
     * @return array{string, string, string}
     */
    public static function stdin(): array
    {
        return (new EmptyInput())->descriptor();
    }

    /**
     * `proc_open` ze stdin z pustego urządzenia i potokami na stdout (1) oraz stderr (2).
     *
     * @param list<string>               $command
     * @param array<int, resource>       $pipes
     * @param array<string, string>|null $environment
     * @param array<string, bool>|null   $options
     *
     * @return resource|false
     */
    public static function open(array $command, array &$pipes, ?string $directory = null, ?array $environment = null, ?array $options = null)
    {
        return proc_open($command, [0 => self::stdin(), 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory, $environment, $options);
    }

    /**
     * Uruchamia polecenie i zwraca kod wyjścia oraz stdout i stderr.
     *
     * @param list<string>               $command
     * @param array<string, string>|null $environment
     *
     * @return array{int, string, string}
     */
    public static function capture(array $command, ?string $directory = null, ?array $environment = null): array
    {
        $pipes = [];
        $process = self::open($command, $pipes, $directory, $environment);
        if ($process === false) {
            return [127, '', ''];
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
