<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Opis decyzji dla człowieka (stderr): profil, skąd lista zmian, co biegnie, co pominięto i dlaczego. Pominięcie jest
 * zawsze widoczne i nigdy nie wygląda jak sprawdzenie.
 */
final class SelectionRenderer
{
    /**
     * @return list<string>
     */
    public function render(Selection $selection, ScopeInput $input): array
    {
        $lines = [
            sprintf('[scope] profil: %s; zmiany: %s', $selection->profile->value, $input->description),
            $selection->full ? '[scope] pełny zestaw bramek: ' . $selection->reason : '[scope] bramki: ' . implode(' ', $selection->gates),
        ];
        if ($selection->skipped !== []) {
            $lines[] = '[scope] pominięte (nic tu nie sprawdzą): ' . $this->skips($selection->skipped);
        }
        if ($selection->wouldSkip !== []) {
            $lines[] = '[scope] lokalnie zostałoby pominięte, tu nic nie jest pomijane: ' . $this->skips($selection->wouldSkip);
        }

        return $lines;
    }

    /**
     * @param list<Skip> $skips
     */
    private function skips(array $skips): string
    {
        return implode('; ', array_map(static fn(Skip $skip): string => sprintf('%s [%s]', $skip->gate, $skip->reason), $skips));
    }
}
