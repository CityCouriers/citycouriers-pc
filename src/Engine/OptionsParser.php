<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Parser opcji `pc run`. Błąd użycia to `UsageException` (kod wyjścia 2).
 */
final class OptionsParser
{
    /**
     * Czy w argumentach jest `--format=json` (ostatnia wartość wygrywa). Dzięki temu błąd użycia też daje dokument JSON.
     *
     * @param list<string> $arguments
     */
    public function wantsJson(array $arguments): bool
    {
        $format = null;
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--format=')) {
                $format = substr($argument, 9);
            }
        }

        return $format === OutputFormat::Json->value;
    }

    /**
     * @param list<string> $arguments argumenty po nazwie polecenia
     *
     * @throws UsageException
     */
    public function parse(array $arguments): RunOptions
    {
        $options = new RunOptions();
        foreach ($arguments as $argument) {
            $options = $this->apply($options, $argument);
        }
        if ($options->scope === RunScope::Staged && $options->base !== null) {
            throw new UsageException('Opcje --staged i --base wykluczają się wzajemnie.');
        }

        return $options;
    }

    /**
     * @throws UsageException
     */
    private function apply(RunOptions $options, string $argument): RunOptions
    {
        if ($argument === '--staged') {
            return new RunOptions(RunScope::Staged, $options->format, $options->base, $options->config);
        }
        if (str_starts_with($argument, '--format=')) {
            return new RunOptions($options->scope, $this->format(substr($argument, 9)), $options->base, $options->config);
        }
        if (str_starts_with($argument, '--base=')) {
            return new RunOptions($options->scope, $options->format, $this->nonEmpty('--base', substr($argument, 7)), $options->config);
        }
        if (str_starts_with($argument, '--config=')) {
            return new RunOptions($options->scope, $options->format, $options->base, $this->nonEmpty('--config', substr($argument, 9)));
        }

        throw new UsageException(sprintf('Nieznana opcja lub argument: %s', $argument));
    }

    /**
     * @throws UsageException
     */
    private function format(string $format): OutputFormat
    {
        return OutputFormat::tryFrom($format) ?? throw new UsageException(sprintf('Nieznana wartość --format: %s (dozwolone: text, json).', $format));
    }

    /**
     * @throws UsageException
     */
    private function nonEmpty(string $option, string $value): string
    {
        if ($value === '') {
            throw new UsageException(sprintf('Opcja %s wymaga wartości.', $option));
        }

        return $value;
    }
}
