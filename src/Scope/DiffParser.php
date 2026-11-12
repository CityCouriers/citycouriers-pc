<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Rozbiór `git diff --raw -z --no-renames` z walidacją formatu. Niczemu nie ufa: ucięte wyjście, token spoza formatu
 * i status inny niż A, D, M (np. T, U, X) to `ScopeException`, a wywołujący uruchamia wtedy pełny zestaw bramek.
 */
final class DiffParser
{
    private const string META = '~^:([0-7]{6}) ([0-7]{6}) [0-9a-f]{4,64} [0-9a-f]{4,64} ([A-Z])$~D';

    private const array SPECIAL_MODES = ['120000', '160000'];

    /**
     * @throws ScopeException
     */
    public function parse(string $output): ParsedDiff
    {
        if ($output === '') {
            return new ParsedDiff([], false);
        }
        if (!str_ends_with($output, "\0")) {
            throw new ScopeException('Niezaufany diff: wyjście gita jest ucięte.');
        }
        $tokens = explode("\0", substr($output, 0, -1));
        if (count($tokens) % 2 !== 0) {
            throw new ScopeException('Niezaufany diff: liczba tokenów niezgodna z formatem.');
        }

        $paths = [];
        $special = false;
        foreach (array_chunk($tokens, 2) as [$meta, $path]) {
            $special = $this->special($meta) || $special;
            $paths[] = $path;
        }

        return new ParsedDiff($paths, $special);
    }

    /**
     * @throws ScopeException
     */
    private function special(string $meta): bool
    {
        if (preg_match(self::META, $meta, $match) !== 1) {
            throw new ScopeException('Niezaufany diff: token spoza formatu.');
        }
        if (!in_array($match[3], ['A', 'D', 'M'], true)) {
            throw new ScopeException(sprintf('Niezaufany diff: status %s inny niż A, D, M.', $match[3]));
        }

        return in_array($match[1], self::SPECIAL_MODES, true) || in_array($match[2], self::SPECIAL_MODES, true);
    }
}
