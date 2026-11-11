<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Uruchamia program bez powłoki i zwraca kod wyjścia oraz stdout albo `null`, gdy programu nie da się uruchomić
 * (brak w PATH, brak uprawnień). Ostrzeżenia PHP są połykane (zob. `Quiet`), wynik niepowodzenia nie ginie.
 */
final class SubProcess
{
    /**
     * @param list<string>               $command
     * @param array<string, string>|null $environment
     */
    public function run(array $command, ?array $environment = null, ?string $directory = null): ?GitOutput
    {
        $pipes = [];
        $process = (new Quiet())->call(static function () use ($command, $directory, $environment, &$pipes) {
            return proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory, $environment);
        });
        $stdout = $pipes[1] ?? null;
        $stderr = $pipes[2] ?? null;
        if ($process === false || !is_resource($stdout) || !is_resource($stderr)) {
            return null;
        }
        $output = (string) stream_get_contents($stdout);
        fclose($stdout);
        fclose($stderr);

        return new GitOutput(proc_close($process), $output);
    }
}
