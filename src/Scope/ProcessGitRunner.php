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
        // Zmienna o nazwie z samych cyfr trafia do tablicy PHP jako klucz int; `proc_open` nie potrafi jej przekazać.
        $environment = ['GIT_OPTIONAL_LOCKS' => '0'] + array_filter(getenv(), is_string(...), ARRAY_FILTER_USE_KEY);

        return $this->processes->run(['git', '-C', $this->directory, ...$arguments], $environment) ?? throw new ScopeException('Nie udało się uruchomić gita.');
    }
}
