<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Deklaracja zakresu bramki dla klasyfikatora (#40): allowlista typów plików i ścieżek, pliki konfiguracji,
 * których zmiana wymusza pełny zakres, oraz powód pominięcia. Pusta lista wyzwalaczy oznacza „zawsze”
 * (fail-closed). Silnik tylko przenosi decyzję klasyfikatora do pola `skipped` / `reason`.
 */
final readonly class GateTriggers
{
    /**
     * @param list<string> $extensions     rozszerzenia (bez kropki), które uruchamiają bramkę
     * @param list<string> $paths          ścieżki i wzorce, które uruchamiają bramkę
     * @param list<string> $fullScopeFiles pliki konfiguracji, których zmiana wymusza pełny zakres
     */
    public function __construct(
        public array $extensions = [],
        public array $paths = [],
        public array $fullScopeFiles = [],
        public string $skipReason = 'Zmienione pliki nie wymagają tej bramki.',
    ) {}
}
