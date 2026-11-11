<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Minimalny dostęp do gita potrzebny silnikowi. Interfejs pozwala testować `pc run` bez prawdziwego repozytorium.
 */
interface Git
{
    public function isRepository(): bool;

    /**
     * Pliki w indeksie (dodane, zmienione, zmienione nazwy), ścieżki względne ze znakami `/`.
     *
     * @return list<string>
     *
     * @throws EngineException gdy nie da się odczytać stage'a
     */
    public function stagedFiles(): array;

    /**
     * Pełny OID commita dla rewizji albo `null`, gdy rewizji nie da się rozwiązać.
     */
    public function resolveCommit(string $revision): ?string;
}
