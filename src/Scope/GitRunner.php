<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Uruchamia `git` z argumentami (bez powłoki). Nie da się uruchomić gita to `ScopeException`.
 */
interface GitRunner
{
    /**
     * @param list<string> $arguments
     *
     * @throws ScopeException
     */
    public function run(array $arguments): GitOutput;
}
