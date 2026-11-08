<?php

declare(strict_types=1);

namespace CityCouriers\Pc;

use CityCouriers\Pc\Config\ConfigException;
use CityCouriers\Pc\Config\ConfigLoader;
use CityCouriers\Pc\Config\ConfigValidator;

/**
 * Wejście `pc`. W wersji szkieletowej: `--version`, `--help` i `config:validate`.
 * Bramki jakości dochodzą po zatwierdzeniu planu przeniesienia (zob. docs/plan-przeniesienia.md).
 */
final class Application
{
    public const int EXIT_OK = 0;

    public const int EXIT_FAILURE = 1;

    public const int EXIT_USAGE = 2;

    public const string DEFAULT_CONFIG = 'pc.json';

    /**
     * @param list<string> $arguments argumenty bez nazwy skryptu
     */
    public function run(array $arguments): CommandResult
    {
        $command = $arguments[0] ?? '--help';

        return match ($command) {
            '--version', '-V' => new CommandResult(self::EXIT_OK, 'pc ' . Version::CURRENT . "\n"),
            '--help', '-h', 'list' => new CommandResult(self::EXIT_OK, $this->help()),
            'config:validate' => $this->validateConfig($arguments[1] ?? self::DEFAULT_CONFIG),
            default => new CommandResult(self::EXIT_USAGE, '', sprintf("Nieznane polecenie: %s\n\n", $command) . $this->help()),
        };
    }

    private function validateConfig(string $path): CommandResult
    {
        try {
            $config = (new ConfigLoader())->load($path);
        } catch (ConfigException $exception) {
            return new CommandResult(self::EXIT_FAILURE, '', $exception->getMessage() . "\n");
        }

        $errors = (new ConfigValidator())->validate($config);
        if ($errors === []) {
            return new CommandResult(self::EXIT_OK, sprintf("Konfiguracja poprawna: %s\n", $path));
        }

        return new CommandResult(self::EXIT_FAILURE, '', implode("\n", $errors) . "\n");
    }

    private function help(): string
    {
        return implode("\n", [
            'pc ' . Version::CURRENT,
            '',
            'Użycie: pc <polecenie>',
            '',
            'Polecenia:',
            '  config:validate [plik]  Sprawdza plik konfiguracji konsumenta (domyślnie pc.json).',
            '  --version, -V           Wypisuje wersję.',
            '  --help, -h, list        Wypisuje tę pomoc.',
            '',
        ]);
    }
}
