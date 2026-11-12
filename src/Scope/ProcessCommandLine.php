<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Argumenty procesu z `/proc/<pid>/cmdline` (dokładne, rozdzielone NUL), a gdzie go nie ma, z `ps -o args=` (podział po
 * białych znakach: wartości z odstępami rozpadają się na osobne tokeny, więc taki commit to `Unknown`, nigdy `Plain`).
 * Windows (Git for Windows uruchamia hook poza drzewem procesów MSYS) i błędy dają `null`.
 */
final readonly class ProcessCommandLine implements CommandLineSource
{
    public function __construct(private SubProcess $processes = new SubProcess()) {}

    public function argv(int $pid): ?array
    {
        if ($pid <= 0) {
            return null;
        }
        $raw = $this->fromProc($pid);
        if ($raw !== null) {
            return $raw;
        }

        return PHP_OS_FAMILY === 'Windows' ? null : $this->fromPs($pid);
    }

    /**
     * Czy na tej platformie istnieje jakiekolwiek źródło (do testów i diagnostyki).
     */
    public function supported(): bool
    {
        return PHP_OS_FAMILY !== 'Windows';
    }

    /**
     * @return list<string>|null
     */
    private function fromProc(int $pid): ?array
    {
        $path = '/proc/' . $pid . '/cmdline';
        $content = is_readable($path) ? (new Quiet())->call(static fn(): string|false => file_get_contents($path)) : false;
        if (!is_string($content) || $content === '') {
            return null;
        }

        return explode("\0", rtrim($content, "\0"));
    }

    /**
     * @return list<string>|null
     */
    private function fromPs(int $pid): ?array
    {
        $result = $this->processes->run(['ps', '-o', 'args=', '-p', (string) $pid]);
        $output = $result === null ? '' : trim($result->stdout);
        if ($result === null || $result->code !== 0 || $output === '') {
            return null;
        }
        $tokens = preg_split('~\s+~', $output);

        return $tokens === false ? null : $tokens;
    }
}
