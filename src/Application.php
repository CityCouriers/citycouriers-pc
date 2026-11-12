<?php

declare(strict_types=1);

namespace CityCouriers\Pc;

use CityCouriers\Pc\Config\ConfigException;
use CityCouriers\Pc\Config\ConfigLoader;
use CityCouriers\Pc\Config\ConfigValidator;
use CityCouriers\Pc\Engine\RunCommandFactory;
use CityCouriers\Pc\Scope\ProcessCommandLine;
use CityCouriers\Pc\Scope\ProcessGitRunner;
use CityCouriers\Pc\Scope\ScopeCommand;
use CityCouriers\Pc\Scope\ScopeDecider;
use CityCouriers\Pc\Scope\ScopeResolver;

/**
 * Wejście `pc`: `--version`, `--help`, `config:validate` i `run` (silnik bramek, zob. docs/bramki.md).
 * Rejestr zaimplementowanych bramek rośnie z przenoszeniem bramek (docs/plan-przeniesienia.md).
 */
final class Application
{
    public const int EXIT_OK = 0;

    public const int EXIT_FAILURE = 1;

    public const int EXIT_USAGE = 2;

    public const string DEFAULT_CONFIG = 'pc.json';

    public function __construct(
        private readonly RunCommandFactory $runCommands = new RunCommandFactory(),
        private readonly ?ScopeCommand $scopeCommand = null,
    ) {}

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
            'run' => $this->runGates(array_slice($arguments, 1)),
            'scope' => $this->scope(array_slice($arguments, 1)),
            default => new CommandResult(self::EXIT_USAGE, '', sprintf("Nieznane polecenie: %s\n\n", $command) . $this->help()),
        };
    }

    /**
     * @param list<string> $arguments
     */
    private function runGates(array $arguments): CommandResult
    {
        return $this->runCommands->create()->execute($arguments);
    }

    /**
     * @param list<string> $arguments
     */
    private function scope(array $arguments): CommandResult
    {
        $command = $this->scopeCommand ?? new ScopeCommand(new ScopeDecider(new ScopeResolver(new ProcessGitRunner((string) getcwd())), new ProcessCommandLine(), getenv()));

        return $command->execute($arguments);
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
            '  run [opcje]             Uruchamia włączone bramki. Opcje: --staged, --format=text|json,',
            '                          --base=<rewizja>, --config=<plik>. Kody wyjścia: docs/bramki.md.',
            '  scope [opcje]           Które bramki uruchomić dla zmienionych plików (hook, CI). Opcje: --profile=local-narrow|enforce-all,',
            '                          --rules=<plik>, --parent-pid=<pid>, --base=<rewizja>. Zob. docs/bramki.md.',
            '  config:validate [plik]  Sprawdza plik konfiguracji konsumenta (domyślnie pc.json).',
            '  --version, -V           Wypisuje wersję.',
            '  --help, -h, list        Wypisuje tę pomoc.',
            '',
        ]);
    }
}
