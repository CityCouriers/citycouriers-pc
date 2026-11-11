<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

use CityCouriers\Pc\Version;

/**
 * Dokument JSON `pc run --format=json` (schemat: docs/schema/pc-run-1.json). Wszystkie pola są zawsze obecne,
 * brak danych to `null`. Czas bramki (`duration_ms`) jest jedynym polem zależnym od czasu.
 */
final class JsonRenderer
{
    public const int SCHEMA_VERSION = 1;

    public function render(RunReport $report): string
    {
        $document = [
            'schema_version' => self::SCHEMA_VERSION,
            'tool' => ['name' => 'pc', 'version' => Version::CURRENT],
            'command' => 'run',
            'scope' => $report->scope?->value,
            'diff_base' => $report->diffBase,
            'status' => $report->status->value,
            'exit_code' => $report->exitCode,
            'complete' => $report->complete,
            'summary' => $report->summary(),
            'gates' => array_map($this->gate(...), $report->entries),
            'error' => $report->error === null ? null : [
                'code' => $report->error->code,
                'message' => $report->error->message,
                'hint' => $report->error->hint,
            ],
        ];

        return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function gate(GateEntry $entry): array
    {
        $result = $entry->result;

        return [
            'name' => $entry->name,
            'mode' => $entry->mode->value,
            'status' => $result->status->value,
            'reason' => $result->reason === null ? null : [
                'code' => $result->reason->code->value,
                'message' => $result->reason->message,
                'blocked_by' => $result->reason->blockedBy,
            ],
            'checked' => $result->checked === null ? null : ['unit' => $result->checked->unit->value, 'count' => $result->checked->count],
            'duration_ms' => $entry->durationMs,
            'diagnostics' => array_map($this->diagnostic(...), $result->diagnostics),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function diagnostic(Diagnostic $diagnostic): array
    {
        return [
            'rule' => $diagnostic->rule,
            'file' => $diagnostic->file,
            'line' => $diagnostic->line,
            'column' => $diagnostic->column,
            'severity' => $diagnostic->severity->value,
            'message' => $diagnostic->message,
            'hint' => $diagnostic->hint,
        ];
    }
}
