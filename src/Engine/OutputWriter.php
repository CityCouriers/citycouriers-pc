<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

use CityCouriers\Pc\CommandResult;

/**
 * Zamienia raport na wynik polecenia: stdout, stderr i kod wyjścia. W trybie JSON stdout niesie wyłącznie
 * jeden dokument, a tekst dla człowieka (komunikat błędu) idzie na stderr.
 */
final class OutputWriter
{
    public function write(RunReport $report, OutputFormat $format): CommandResult
    {
        if ($format === OutputFormat::Json) {
            return new CommandResult($report->exitCode, (new JsonRenderer())->render($report), $this->humanText($report));
        }

        if ($report->status === RunStatus::UsageError || $report->status === RunStatus::ConfigError) {
            return new CommandResult($report->exitCode, '', $this->humanText($report));
        }

        return new CommandResult($report->exitCode, (new TextRenderer())->render($report));
    }

    private function humanText(RunReport $report): string
    {
        if ($report->error === null) {
            return '';
        }

        return $report->error->message . "\n" . ($report->error->hint === null ? '' : $report->error->hint . "\n");
    }
}
