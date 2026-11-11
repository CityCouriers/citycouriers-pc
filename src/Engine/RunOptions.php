<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Opcje `pc run`: `--staged`, `--format=text|json`, `--base=<rewizja>`, `--config=<plik>`.
 */
final readonly class RunOptions
{
    public function __construct(
        public RunScope $scope = RunScope::Full,
        public OutputFormat $format = OutputFormat::Text,
        public ?string $base = null,
        public string $config = 'pc.json',
    ) {}
}
