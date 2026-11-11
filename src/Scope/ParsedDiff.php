<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Zaufana lista zmian z `git diff --raw -z --no-renames`: ścieżki i informacja o dowiązaniu symbolicznym albo submodule.
 */
final readonly class ParsedDiff
{
    /**
     * @param list<string> $paths
     */
    public function __construct(
        public array $paths,
        public bool $specialModes,
    ) {}
}
