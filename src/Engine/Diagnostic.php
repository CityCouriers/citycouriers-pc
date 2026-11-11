<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Jedno naruszenie. Dane, których nie ma, to `null` (np. diagnostyka całego repo nie ma pliku ani linii).
 */
final readonly class Diagnostic
{
    public function __construct(
        public string $message,
        public ?string $rule = null,
        public ?string $file = null,
        public ?int $line = null,
        public ?int $column = null,
        public Severity $severity = Severity::Error,
        public ?string $hint = null,
    ) {}
}
