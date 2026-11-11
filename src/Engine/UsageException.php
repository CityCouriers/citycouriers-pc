<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

use RuntimeException;

/**
 * Błąd użycia `pc run`: kod wyjścia 2. `errorCode` trafia do `error.code` w JSON.
 */
final class UsageException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'usage_error',
        public readonly ?string $hint = null,
    ) {
        parent::__construct($message);
    }
}
