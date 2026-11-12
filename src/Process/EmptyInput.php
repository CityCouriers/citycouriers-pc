<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Process;

/**
 * Deskryptor stdin dla procesów potomnych: puste urządzenie (`NUL` albo `/dev/null`).
 *
 * Proces potomny bez jawnego stdin dziedziczy stdin rodzica: terminal hooka `pre-commit`, a w `pre-push` listę refów
 * od gita. Program, który z niego czyta albo zmienia tryb terminala (na przykład bash z Git for Windows pod mintty),
 * zawiesza się, zjada cudze dane albo psuje wyjście i urywa bieg rodzica. Każdy proces uruchamiany przez `pc`
 * dostaje więc pusty stdin.
 */
final readonly class EmptyInput
{
    /**
     * @return array{string, string, string}
     */
    public function descriptor(): array
    {
        return ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'];
    }
}
