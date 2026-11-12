<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Tests\Support;

/**
 * Wpis wyjścia `git diff --raw -z` do testów rozbioru diffu.
 */
final class RawDiff
{
    public static function entry(string $oldMode, string $newMode, string $status, string $file): string
    {
        return ':' . $oldMode . ' ' . $newMode . ' ' . str_repeat('a', 40) . ' ' . str_repeat('b', 40) . ' ' . $status . "\0" . $file . "\0";
    }
}
