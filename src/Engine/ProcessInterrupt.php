<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Przechwytuje SIGINT i SIGTERM (pcntl) albo Ctrl+C (Windows) i tylko zapamiętuje sygnał. Dzięki temu proces nie
 * ginie w środku bramki ani fazy `finalize`: sygnał w trakcie `finalize` jest odroczony do jej końca.
 * Bez pcntl i bez obsługi Ctrl+C silnik działa jak bez przerwań.
 */
final class ProcessInterrupt implements InterruptSignal
{
    private const int SIGINT = 2;

    private const int SIGTERM = 15;

    private ?int $received = null;

    public function __construct()
    {
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(self::SIGINT, $this->remember(...));
            pcntl_signal(self::SIGTERM, $this->remember(...));

            return;
        }

        if (function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(fn() => $this->remember(self::SIGINT));
        }
    }

    public function signal(): ?int
    {
        return $this->received;
    }

    private function remember(int $signal): void
    {
        $this->received ??= $signal;
    }
}
