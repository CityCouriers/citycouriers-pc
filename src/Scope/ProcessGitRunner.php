<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Git przez proces potomny w danym katalogu. Dziedziczy środowisko (w hooku `GIT_INDEX_FILE` wskazuje właściwy indeks),
 * ale wyłącza opcjonalne blokady, żeby diagnostyka nie kolidowała z trwającym commitem.
 */
final readonly class ProcessGitRunner implements GitRunner
{
    public function __construct(
        private string $directory,
        private SubProcess $processes = new SubProcess(),
    ) {}

    public function run(array $arguments): GitOutput
    {
        $environment = array_merge(getenv(), ['GIT_OPTIONAL_LOCKS' => '0']);

        return $this->processes->run(['git', '-C', $this->directory, ...$arguments], $environment) ?? throw new ScopeException('Nie udało się uruchomić gita.');
    }
}
