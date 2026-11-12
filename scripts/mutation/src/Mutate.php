<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Dev\Mutation;

use RuntimeException;

/**
 * Jedyny udokumentowany sposób uruchamiania mutacji w repo (`composer mutate`, #60). Kolejno: kontrola zerowa i dodatnia
 * na zestawie kontrolnym, kontrola tożsamościowa (pełny zestaw pod wrapperem plików wtyczki, bez mutacji), bieg Pest
 * Mutate na celach, manifest i werdykt. Polecenie Pesta składa skrypt, nie użytkownik.
 *
 * Kody wyjścia: 0 bieg przyjęty, 1 bieg odrzucony (powody w manifeście i na wyjściu), 2 błąd użycia lub środowiska.
 */
final class Mutate
{
    public const array MUTATORS = ['SetDefault', 'SetLaravel', 'SetVisibility', 'EqualityIdenticalToEqual', 'EqualityNotIdenticalToNotEqual'];

    public const string CONTROL = 'scripts/mutation/control';

    private const string ZERO_MUTANTS = 'scripts/mutation/bez-mutantow.json';

    /**
     * Domyślna liczba procesów mutantów. Przy 32 procesach mutanty pokryte testami integracyjnymi (git, bash) przekraczały
     * limit czasu wtyczki (czas zestawu początkowego + 20%) z powodu obciążenia, nie zawieszenia (pomiar w #60).
     */
    public const int PROCESSES = 8;

    private const string USAGE = <<<'TXT'
        Użycie: composer mutate -- [--path=src] [--serial | --processes=N] [--out=build/mutation]

          --path=ŚCIEŻKA  cel biegu: katalog albo plik (domyślnie src, bieg autorytatywny); kawałek to tryb roboczy
          --serial        bez --parallel (domyślnie równolegle, jak bieg autorytatywny z #60)
          --processes=N   liczba równoległych procesów mutantów (domyślnie 8)
          --out=KATALOG   katalog na manifest, logi i dzienniki (domyślnie build/mutation)

        Kody wyjścia: 0 bieg przyjęty, 1 bieg odrzucony, 2 błąd użycia lub środowiska.
        TXT;

    public function __construct(
        private readonly string $root,
        private readonly bool $echo = true,
    ) {}

    /**
     * @param list<string> $arguments argumenty bez nazwy skryptu
     */
    public function main(array $arguments): int
    {
        try {
            $options = self::options($arguments);
        } catch (RuntimeException $exception) {
            $this->say($exception->getMessage() . "\n\n" . self::USAGE);

            return 2;
        }
        if ($options['help']) {
            $this->say(self::USAGE);

            return 0;
        }
        $mainIni = Environment::coverageIni($this->root . '/' . explode('/', $options['path'])[0]);
        $controlIni = Environment::coverageIni($this->root . '/' . self::CONTROL);
        if ($mainIni === null || $controlIni === null) {
            $this->say('Brak sterownika pokrycia (pcov albo xdebug): Pest Mutate nie ustali, które testy pokrywają mutanta.');

            return 2;
        }
        $out = $this->root . '/' . $options['out'];
        if (!is_dir($out) && !mkdir($out, 0o777, true)) {
            $this->say('Nie można utworzyć katalogu ' . $options['out']);

            return 2;
        }

        $parallel = $options['parallel'] ? ['--parallel', '--processes=' . $options['processes']] : [];

        return $this->run($options['path'], $parallel, $out, $mainIni, $controlIni);
    }

    /**
     * @param list<string> $parallel opcje równoległości Pesta (puste: bieg szeregowy)
     * @param list<string> $mainIni
     * @param list<string> $controlIni
     */
    private function run(string $scope, array $parallel, string $out, array $mainIni, array $controlIni): int
    {
        $environment = Environment::collect($this->root);
        $reasons = Environment::problems($this->root, $environment);
        $targets = Inventory::scan($this->root, $scope);

        $this->say("\n== Kontrola zerowa i dodatnia (" . self::CONTROL . ")\n");
        $controlReasons = $this->control($out, $controlIni, $parallel);
        $reasons = [...$reasons, ...$controlReasons];

        $this->say("\n== Kontrola tożsamościowa (pełny zestaw testów pod wrapperem plików wtyczki, bez mutacji)\n");
        $reasons = [...$reasons, ...$this->identity($out, $targets)];

        $command = self::command($this->root, $mainIni, ['--path=' . $scope, '--mutator=' . implode(',', self::MUTATORS)], $parallel);
        $journalPath = $out . '/journal.jsonl';
        $logPath = $out . '/run.log';
        $exitCode = null;
        if ($controlReasons === []) {
            $this->say("\n== Bieg mutacji: " . $scope . "\n");
            $exitCode = $this->pest($command, $journalPath, $logPath);
        } else {
            $reasons[] = 'bieg mutacji pominięty: kontrola zerowa lub dodatnia nie przeszła';
            file_put_contents($journalPath, '');
            file_put_contents($logPath, '');
        }

        $journal = Journal::parse((string) file_get_contents($journalPath), $this->root);
        $log = RunLog::parse((string) file_get_contents($logPath));
        $zeroMutants = ZeroMutants::parse((string) @file_get_contents($this->root . '/' . self::ZERO_MUTANTS));
        $verdict = Acceptance::evaluate($targets, $scope, $journal, $log, $exitCode, $zeroMutants, [], $reasons);

        $redactor = new Redactor($this->root, PHP_BINARY, getenv('USERPROFILE') ?: (getenv('HOME') ?: null));
        $manifest = Manifest::build($environment, $redactor->redact(implode(' ', $command)), $scope, $targets, $journal, $log, $verdict);
        file_put_contents($out . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        foreach (['run.log', 'journal.jsonl', 'control.log', 'control.jsonl', 'identity.log'] as $file) {
            $redactor->redactFile($out . '/' . $file);
        }
        $this->say("\n" . Manifest::report($verdict, $out === '' ? '.' : $redactor->redact($out)));

        return $verdict->accepted() ? 0 : 1;
    }

    /**
     * Polecenie Pest Mutate. Bieg kontrolny i główny różnią się wyłącznie celem (konfiguracja, ścieżka, mutatory), więc
     * kontrola sprawdza te same opcje równoległości i wtyczki co bieg właściwy: przy `--processes` bez łatki kontrola
     * z innymi opcjami przeszła, a bieg właściwy liczył każdy pokryty mutant jako zabity (#60).
     *
     * @param list<string> $ini ustawienia PHP (-d)
     * @param list<string> $target opcje celu
     * @param list<string> $parallel opcje równoległości
     *
     * @return list<string>
     */
    public static function command(string $root, array $ini, array $target, array $parallel): array
    {
        return [PHP_BINARY, '-d', 'memory_limit=2G', ...$ini, $root . '/vendor/bin/pest', '--mutate', '--everything', ...$target, '--no-cache', ...$parallel];
    }

    /**
     * @param list<string> $ini
     * @param list<string> $parallel
     *
     * @return list<string>
     */
    private function control(string $out, array $ini, array $parallel): array
    {
        $journalPath = $out . '/control.jsonl';
        $logPath = $out . '/control.log';
        $target = ['--configuration=' . self::CONTROL . '/phpunit.xml', '--path=' . self::CONTROL . '/src', '--mutator=ArithmeticPlusToMinus'];
        $exitCode = $this->pest(self::command($this->root, $ini, $target, $parallel), $journalPath, $logPath);

        return Controls::mutation(
            Journal::parse((string) file_get_contents($journalPath), $this->root),
            RunLog::parse((string) file_get_contents($logPath)),
            $exitCode,
        );
    }

    /**
     * @param list<Target> $targets
     *
     * @return list<string>
     */
    private function identity(string $out, array $targets): array
    {
        if ($targets === []) {
            return ['kontrola tożsamościowa: brak celów do podstawienia pod wrapper'];
        }
        $source = $this->root . '/' . $targets[0]->path;
        $copy = $out . '/identity-source.php';
        copy($source, $copy);
        $logPath = $out . '/identity.log';
        $env = ['PEST_MUTATION_TESTING' => (string) realpath($source), 'PEST_MUTATION_FILE' => (string) realpath($copy), 'COLUMNS' => '300'];
        $exitCode = PestRun::run([PHP_BINARY, '-d', 'xdebug.mode=off', $this->root . '/vendor/bin/pest'], $this->root, $env, $logPath, $this->echo);
        unlink($copy);

        return Controls::identity($exitCode, (string) file_get_contents($logPath));
    }

    /**
     * @param list<string> $command
     */
    private function pest(array $command, string $journalPath, string $logPath): ?int
    {
        file_put_contents($journalPath, '');

        return PestRun::run($command, $this->root, ['PC_MUTATION_JOURNAL' => $journalPath, 'COLUMNS' => '300'], $logPath, $this->echo);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{path: string, parallel: bool, processes: int, out: string, help: bool}
     */
    public static function options(array $arguments): array
    {
        $options = ['path' => 'src', 'parallel' => true, 'processes' => self::PROCESSES, 'out' => 'build/mutation', 'help' => false];
        foreach ($arguments as $argument) {
            if ($argument === '--help' || $argument === '-h') {
                $options['help'] = true;
            } elseif ($argument === '--serial') {
                $options['parallel'] = false;
            } elseif (preg_match('/^--processes=([1-9]\d{0,2})$/', $argument, $matches) === 1) {
                $options['processes'] = (int) $matches[1];
            } elseif (preg_match('/^--(path|out)=(.+)$/', $argument, $matches) === 1) {
                $options[$matches[1]] = self::safePath($matches[2]);
            } else {
                throw new RuntimeException('Nieznany argument: ' . $argument);
            }
        }

        return $options;
    }

    /**
     * Ścieżka względna wobec repo, z `/`, bez `..` (skrypt nie pisze ani nie mutuje poza repo).
     */
    private static function safePath(string $path): string
    {
        $normalized = trim(str_replace('\\', '/', $path), '/');
        $segments = explode('/', $normalized);
        if ($normalized === '' || in_array('..', $segments, true) || preg_match('~^[A-Za-z]:~', $normalized) === 1 || str_starts_with($path, '/')) {
            throw new RuntimeException('Ścieżka musi być względna wobec repo i nie może zawierać `..`: ' . $path);
        }

        return $normalized;
    }

    private function say(string $text): void
    {
        if ($this->echo) {
            fwrite(STDOUT, $text . "\n");
        }
    }
}
