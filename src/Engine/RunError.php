<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Obiekt `error` dokumentu JSON (kody wyjścia 2, 3, 5, 130, 143). `code`: usage_error, invalid_base,
 * config_error, engine_error, interrupted.
 */
final readonly class RunError
{
    public function __construct(
        public string $code,
        public string $message,
        public ?string $hint = null,
    ) {}
}
