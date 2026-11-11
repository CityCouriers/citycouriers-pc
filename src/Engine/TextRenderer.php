<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Minimalne wyjście tekstowe `pc run`. Wygląd (kolory, `NO_COLOR`, raport HTML) projektuje #37; tu liczy się
 * zgodność z JSON i kodem wyjścia: słowo „OK” pojawia się wyłącznie w ostatniej linii, gdy `status` to `passed`
 * i `complete` to `true`.
 */
final class TextRenderer
{
    public function render(RunReport $report): string
    {
        $lines = [];
        foreach ($report->entries as $entry) {
            array_push($lines, ...$this->gateLines($entry));
        }
        $lines[] = $this->lastLine($report);

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return list<string>
     */
    private function gateLines(GateEntry $entry): array
    {
        $result = $entry->result;
        $line = sprintf('%s: %s', $entry->name, $this->label($entry));
        if ($result->reason !== null) {
            $line .= sprintf(' (%s: %s)', $result->reason->code->value, $result->reason->message);
        }
        $lines = [$line];
        foreach ($result->diagnostics as $diagnostic) {
            $lines[] = '  ' . $this->diagnosticLine($diagnostic);
            if ($diagnostic->hint !== null) {
                $lines[] = '    podpowiedź: ' . $diagnostic->hint;
            }
        }

        return $lines;
    }

    private function label(GateEntry $entry): string
    {
        return match ($entry->result->status) {
            GateStatus::Passed => 'przeszła',
            GateStatus::Failed => $entry->mode === GateMode::Blocking ? 'zatrzymała' : 'uwagi',
            GateStatus::Skipped => 'pominięta',
            GateStatus::NotScanned => 'NIE PRZESKANOWANO',
        };
    }

    private function diagnosticLine(Diagnostic $diagnostic): string
    {
        $where = $diagnostic->file === null ? '' : $diagnostic->file . ($diagnostic->line === null ? '' : ':' . $diagnostic->line . ($diagnostic->column === null ? '' : ':' . $diagnostic->column)) . ' ';
        $rule = $diagnostic->rule === null ? '' : '[' . $diagnostic->rule . '] ';

        return $where . $rule . $diagnostic->message;
    }

    private function lastLine(RunReport $report): string
    {
        $summary = $report->summary();
        $counts = sprintf('bramek: %d, przeszły: %d, zatrzymały: %d, pominięte: %d, nie przeskanowano: %d', $summary['gates'], $summary['passed'], $summary['failed'], $summary['skipped'], $summary['not_scanned']);

        return match ($report->status) {
            RunStatus::Passed => $report->complete ? 'OK: ' . $counts : 'NIEPEŁNY: ' . $this->notScannedList($report) . ' (' . $counts . ')',
            RunStatus::Failed => 'ZATRZYMANO: ' . $counts,
            RunStatus::Incomplete => 'NIEPEŁNY: ' . $this->notScannedList($report) . ' (' . $counts . ')',
            default => 'BŁĄD (' . $report->status->value . '): ' . ($report->error->message ?? ''),
        };
    }

    private function notScannedList(RunReport $report): string
    {
        $names = [];
        foreach ($report->entries as $entry) {
            if ($entry->result->status === GateStatus::NotScanned) {
                $names[] = $entry->name;
            }
        }

        return 'nie przeskanowano: ' . implode(', ', $names);
    }
}
