<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Polityka egzekwowania (#40), osobna od klasyfikacji: `local-narrow` pomija bramki z listy `skipped`, `enforce-all`
 * zawsze zwraca pełny zestaw, a lista klasyfikatora jest wtedy tylko informacją. Lista zmienionych plików nigdy nie
 * zmniejsza zbioru kontroli w `enforce-all`.
 */
final class EnforcementPolicy
{
    /**
     * @param list<string> $allGates wszystkie bramki zestawu reguł
     */
    public function select(Classification $classification, Profile $profile, array $allGates): Selection
    {
        if ($profile === Profile::LocalNarrow) {
            return new Selection($profile, $classification->gates, $classification->skipped, [], $classification->full, $classification->reason);
        }

        return new Selection($profile, $allGates, [], $classification->skipped, true, 'profil enforce-all: pełny zestaw bez względu na pliki');
    }
}
