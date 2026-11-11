<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

use CityCouriers\Pc\CommandResult;
use CityCouriers\Pc\Config\ConfigException;

/**
 * Polecenie `pc run` i `pc run --staged`: opcje, przygotowanie, runner, wyjście. Kolejność błędów:
 * użycie opcji (2), konfiguracja (3), repozytorium i `--base` (2), reszta to bieg (0, 1, 4, 5, 130, 143).
 */
final readonly class RunCommand
{
    public function __construct(
        private Runner $runner,
        private RunPreparer $preparer,
    ) {}

    /**
     * @param list<string> $arguments argumenty po nazwie polecenia
     */
    public function execute(array $arguments): CommandResult
    {
        $parser = new OptionsParser();
        $writer = new OutputWriter();
        try {
            $options = $parser->parse($arguments);
        } catch (UsageException $exception) {
            return $writer->write((new ReportBuilder())->usage($exception), $parser->wantsJson($arguments) ? OutputFormat::Json : OutputFormat::Text);
        }

        return $writer->write($this->report($options), $options->format);
    }

    private function report(RunOptions $options): RunReport
    {
        $reports = new ReportBuilder();
        try {
            $prepared = $this->preparer->prepare($options);
        } catch (ConfigException $exception) {
            return $reports->config($exception);
        } catch (UsageException $exception) {
            return $reports->usage($exception);
        } catch (EngineException $exception) {
            return $reports->engine($exception, $options->scope);
        }

        return $this->runner->run($prepared->enabled, $prepared->context);
    }
}
