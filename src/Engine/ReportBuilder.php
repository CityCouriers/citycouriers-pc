<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

use CityCouriers\Pc\Config\ConfigException;

/**
 * Składa raport i ustala kod wyjścia z jawną regułą pierwszeństwa:
 * przerwanie (130/143) > błąd silnika (5) > `failed` w bramce blokującej (1) > `not_scanned` w bramce blokującej (4) > 0.
 * Bramki raportujące kodu nie zmieniają, ale `not_scanned` w każdej z nich zdejmuje `complete`.
 */
final class ReportBuilder
{
    private const int SIGINT = 2;

    /**
     * Raport bez wykonanych bramek: błąd użycia, błąd konfiguracji i awarie przed biegiem.
     */
    public function failure(RunStatus $status, int $exitCode, RunError $error, ?RunScope $scope = null): RunReport
    {
        return new RunReport($status, $exitCode, false, $scope, null, [], $error);
    }

    public function usage(UsageException $exception): RunReport
    {
        return $this->failure(RunStatus::UsageError, 2, new RunError($exception->errorCode, $exception->getMessage(), $exception->hint));
    }

    public function config(ConfigException $exception): RunReport
    {
        return $this->failure(RunStatus::ConfigError, 3, new RunError('config_error', $exception->getMessage(), 'Sprawdź plik poleceniem `pc config:validate`.'));
    }

    public function engine(EngineException $exception, RunScope $scope): RunReport
    {
        return $this->failure(RunStatus::Error, 5, new RunError('engine_error', $exception->getMessage()), $scope);
    }

    /**
     * @param list<GateEntry> $entries
     * @param list<string> $finalizeFailures awarie `finalize` dopisywane do komunikatu błędu, także po sygnale i błędzie silnika
     */
    public function fromRun(array $entries, GateContext $context, ?int $signal, ?string $engineError, array $finalizeFailures = []): RunReport
    {
        $notScanned = array_filter($entries, static fn(GateEntry $entry): bool => $entry->result->status === GateStatus::NotScanned) !== [];

        if ($signal !== null) {
            $name = $signal === self::SIGINT ? 'SIGINT' : 'SIGTERM';
            $error = new RunError('interrupted', $this->withFailures(sprintf('Bieg przerwany sygnałem %s.', $name), $finalizeFailures));

            return new RunReport(RunStatus::Interrupted, $signal === self::SIGINT ? 130 : 143, false, $context->scope, $context->diffBase, $entries, $error);
        }
        if ($engineError !== null) {
            return new RunReport(RunStatus::Error, 5, false, $context->scope, $context->diffBase, $entries, new RunError('engine_error', $this->withFailures($engineError, $finalizeFailures)));
        }

        [$status, $code] = $this->verdict($entries);

        return new RunReport($status, $code, !$notScanned, $context->scope, $context->diffBase, $entries, null);
    }

    /**
     * @param list<string> $failures
     */
    private function withFailures(string $message, array $failures): string
    {
        $extra = array_filter($failures, static fn(string $failure): bool => !str_contains($message, $failure));

        return implode(' ', [$message, ...$extra]);
    }

    /**
     * @param list<GateEntry> $entries
     *
     * @return array{RunStatus, int}
     */
    private function verdict(array $entries): array
    {
        $blocking = array_filter($entries, static fn(GateEntry $entry): bool => $entry->mode === GateMode::Blocking);
        $has = static fn(GateStatus $status): bool => array_filter($blocking, static fn(GateEntry $entry): bool => $entry->result->status === $status) !== [];

        return match (true) {
            $has(GateStatus::Failed) => [RunStatus::Failed, 1],
            $has(GateStatus::NotScanned) => [RunStatus::Incomplete, 4],
            default => [RunStatus::Passed, 0],
        };
    }
}
