<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Jedna definicja „bezpiecznej ścieżki” dla klasyfikatora i wyzwalaczy bramek silnika: względna, ze znakami `/`,
 * bez `\`, litery dysku, `..`, `.` i pustych segmentów. Wszystko inne wymusza pełny zakres (nie ufamy dziwnym ścieżkom).
 */
final class PathSanity
{
    public function isSane(string $path): bool
    {
        if ($path === '' || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('~^[A-Za-z]:~', $path) === 1) {
            return false;
        }
        $segments = explode('/', $path);

        return !in_array('', $segments, true) && !in_array('..', $segments, true) && !in_array('.', $segments, true);
    }
}
