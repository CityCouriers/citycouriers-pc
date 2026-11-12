<?php

declare(strict_types=1);

namespace Pest\Mutate;

use ParaTest\Options;
use Pest\Mutate\Event\Facade;
use Pest\Mutate\Plugins\Mutate;
use Pest\Mutate\Repositories\TelemetryRepository;
use Pest\Mutate\Support\Configuration\Configuration;
use Pest\Mutate\Support\MutationTestResult;
use Pest\Support\Container;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Kopia klasy `Pest\Mutate\MutationTest` z pestphp/pest-plugin-mutate 5.0.2 (licencja MIT, „Copyright (c) Pest and
 * contributors”, pełna nota w LICENSE-pest-plugin-mutate obok). Ładowana zamiast oryginału przez autoload.php
 * (autoload-dev Composera), więc działa w każdym wywołaniu Pesta w tym repo, także ręcznym.
 *
 * Zmiany względem oryginału (#60):
 * 1. Proces mutanta to `[PHP_BINARY, skrypt Pesta, ...argumenty]`. Oryginał startuje od argv[0] rodzica, a Symfony
 *    Process na Windows przepuszcza polecenie przez cmd.exe: `vendor/bin/pest` w ogóle nie startuje, a `vendor\bin\pest`
 *    idzie przez `pest.bat`, który ponownie parsuje argumenty i rozcina filtr na `|`. W obu przypadkach proces kończy się
 *    kodem niezerowym bez uruchomienia testu, a wtyczka liczy to jako zabicie mutanta.
 * 2. Filtr dłuższy niż MAX_FILTER_LENGTH zwija się do nazw klas testów. Dłuższy wiersz przekracza limit cmd.exe
 *    (8191 znaków), filtr przepada, biegnie cały zestaw i mutant kończy się timeoutem. Nadmiar testów nie fałszuje
 *    wyniku: testy, które nie wykonują zmutowanego kodu, przechodzą.
 * 3. Dziennik: gdy ustawiona jest zmienna JOURNAL_ENV, każdy wygenerowany mutant i jego wynik trafiają do pliku jako
 *    wiersze JSON. Skrypt scripts/mutation liczy bieg po identyfikatorach mutantów z dziennika.
 * 4. Proces mutanta nie dostaje `--parallel` ani `--processes`. Oryginał usuwa z argumentów `--parallel`, ale zostawia
 *    `--processes=N`; pojedynczy Pest odrzuca tę opcję kodem 2 bez uruchomienia testu, a wtyczka liczy to jako zabicie
 *    (każdy pokryty mutant przy `--parallel --processes=N`).
 *
 * Strażnik dryfu: test porównuje SHA-256 oryginału w vendor z UPSTREAM_SHA256. Po aktualizacji wtyczki przenieś zmiany
 * na nowy oryginał i zaktualizuj skrót.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter") sygnatura `start` musi zgadzać się z oryginałem
 */
class MutationTest
{
    public const string UPSTREAM_SHA256 = 'ad2af87411b439e362775d28c060b0a5da45cc5ae162cf3f20c60bbb8bfa7a48';

    public const string JOURNAL_ENV = 'PC_MUTATION_JOURNAL';

    public const int MAX_FILTER_LENGTH = 6000;

    private MutationTestResult $result = MutationTestResult::None;

    private ?float $start = null;

    private ?float $finish = null;

    private Process $process;

    private int $patterns = 0;

    private string $filterMode = 'brak';

    public function __construct(public readonly Mutation $mutation)
    {
        self::journal([
            'event' => 'generated',
            'id' => $mutation->id,
            'file' => (string) $mutation->file->getRealPath(),
            'mutator' => $mutation->mutator,
            'start_line' => $mutation->startLine,
            'end_line' => $mutation->endLine,
        ]);
    }

    public function getId(): string
    {
        return $this->mutation->id;
    }

    public function result(): MutationTestResult
    {
        return $this->result;
    }

    public function updateResult(MutationTestResult $result): void
    {
        $this->result = $result;
    }

    /**
     * @param array<string, array<int, array<int, string>>> $coveredLines
     * @param array<int, string> $originalArguments
     */
    public function start(array $coveredLines, Configuration $configuration, array $originalArguments, ?int $processId = null): bool
    {
        $filters = [];
        $classes = [];
        foreach (range($this->mutation->startLine, $this->mutation->endLine) as $lineNumber) {
            foreach ($coveredLines[$this->mutation->file->getRealPath()][$lineNumber] ?? [] as $test) {
                if (preg_match('/\\\\([a-zA-Z0-9]*)::(__pest_evaluable_)?([^#]*)"?/', $test, $matches) !== 1) {
                    continue;
                }

                $filters[] = $matches[2] === '__pest_evaluable_'
                    ? $matches[1] . '::(.*)' . str_replace(['__', '_'], ['.{1,2}', '.'], $matches[3])
                    : $matches[1] . '::(.*)' . $matches[3];
                $classes[] = $matches[1] . '::';
            }
        }
        $filters = array_values(array_unique($filters));
        $this->patterns = count($filters);

        if ($filters === []) {
            $this->updateResult(MutationTestResult::Uncovered);

            Facade::instance()->emitter()->mutationUncovered($this);

            $this->record(null, '');

            return false;
        }

        $envs = [
            Mutate::ENV_MUTATION_TESTING => $this->mutation->file->getRealPath(),
            Mutate::ENV_MUTATION_FILE => $this->mutation->modifiedSourcePath,
        ];

        if ($processId !== null) {
            $envs['PARATEST'] = '1';
            $envs[Options::ENV_KEY_TOKEN] = (string) $processId;
            $envs[Options::ENV_KEY_UNIQUE_TOKEN] = uniqid($processId . '_');
            $envs['LARAVEL_PARALLEL_TESTING'] = '1';
        }

        [$filter, $this->filterMode] = self::filterArgument($filters, array_values(array_unique($classes)));

        $process = new Process(
            command: [...self::command($originalArguments), '--bail', ...($filter === null ? [] : [$filter])],
            env: $envs,
            timeout: $this->calculateTimeout(),
        );

        $this->start = microtime(true);

        $process->start();

        $this->process = $process;

        return true;
    }

    /**
     * Polecenie procesu mutanta: interpreter PHP, skrypt Pesta z argv[0] rodzica i pozostałe argumenty bez opcji pokrycia
     * i równoległości (proces mutanta to jeden zwykły bieg Pesta z filtrem).
     *
     * @param array<int, string> $originalArguments argv rodzica (indeks 0 to skrypt Pesta)
     *
     * @return list<string>
     */
    public static function command(array $originalArguments): array
    {
        $arguments = array_values($originalArguments);
        $script = array_shift($arguments) ?? 'vendor/bin/pest';
        $resolved = realpath($script);
        $childArgument = static fn(string $argument): bool => !str_starts_with($argument, '--coverage')
            && $argument !== '--parallel' && !str_starts_with($argument, '--processes');

        return [PHP_BINARY, $resolved === false ? $script : $resolved, ...array_values(array_filter($arguments, $childArgument))];
    }

    /**
     * Argument `--filter` procesu mutanta i jego tryb: `testy` (wzorce testów jak w oryginale), `klasy` (zwinięte do
     * klas, gdy pełny filtr jest za długi) albo `brak` (null: także zwinięty filtr jest za długi, biegnie cały zestaw).
     *
     * @param list<string> $filters wzorce testów
     * @param list<string> $classes wzorce klas testów (`Klasa::`)
     *
     * @return array{?string, string}
     */
    public static function filterArgument(array $filters, array $classes): array
    {
        foreach ([[$filters, 'testy'], [$classes, 'klasy']] as [$patterns, $mode]) {
            $argument = '--filter="' . implode('|', $patterns) . '"';
            if (strlen($argument) <= self::MAX_FILTER_LENGTH) {
                return [$argument, $mode];
            }
        }

        return [null, 'brak'];
    }

    private function calculateTimeout(): int
    {
        /** @var TelemetryRepository $telemetryRepository */
        $telemetryRepository = Container::getInstance()->get(TelemetryRepository::class);

        $initialTestSuiteDuration = $telemetryRepository->getInitialTestSuiteDuration();

        return (int) ($initialTestSuiteDuration + max(5, $initialTestSuiteDuration * 0.2));
    }

    public function hasFinished(): bool
    {
        try {
            if ($this->process->isRunning()) {
                $this->process->checkTimeout();

                return false;
            }
        } catch (ProcessTimedOutException) {
            $this->updateResult(MutationTestResult::Timeout);

            Facade::instance()->emitter()->mutationTimedOut($this);

            $this->finish = microtime(true);

            $this->record(null, $this->process->getOutput());

            return true;
        }

        if ($this->process->isSuccessful()) {
            $this->updateResult(MutationTestResult::Untested);

            Facade::instance()->emitter()->mutationEscaped($this);
        } else {
            $this->updateResult(MutationTestResult::Tested);

            Facade::instance()->emitter()->mutationTested($this);
        }

        $this->finish = microtime(true);

        $this->record($this->process->getExitCode(), $this->process->getOutput());

        return true;
    }

    public function duration(): float
    {
        if ($this->start === null) {
            return 0;
        }
        if ($this->finish === null) {
            return 0;
        }

        return $this->finish - $this->start;
    }

    /**
     * @param array<string, string> $results
     */
    public function lastRunResult(array $results): MutationTestResult
    {
        return MutationTestResult::from($results[$this->getId()] ?? 'none');
    }

    /**
     * Linia podsumowania Pesta (`Tests: ...`) z wyjścia procesu mutanta albo null, gdy test nie wystartował.
     */
    public static function summary(string $output): ?string
    {
        $plain = preg_replace('/\e\[[0-9;]*m/', '', $output) ?? $output;

        return preg_match('/^\s*Tests:\s+(.+?)\s*$/m', $plain, $matches) === 1 ? $matches[1] : null;
    }

    private function record(?int $exitCode, string $output): void
    {
        self::journal([
            'event' => 'result',
            'id' => $this->mutation->id,
            'status' => $this->result->value,
            'exit_code' => $exitCode,
            'patterns' => $this->patterns,
            'filter' => $this->filterMode,
            'duration' => round($this->duration(), 3),
            'summary' => self::summary($output),
        ]);
    }

    /**
     * @param array<string, int|float|string|null> $entry
     */
    private static function journal(array $entry): void
    {
        $path = getenv(self::JOURNAL_ENV);
        if (!is_string($path) || $path === '') {
            return;
        }
        $line = json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            throw new RuntimeException('Nie udało się dopisać do dziennika mutacji: ' . $path);
        }
    }
}
