<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

use CityCouriers\Pc\Scope\PathSanity;

/**
 * Deklaracja zakresu bramki dla klasyfikatora (#40): allowlista typów plików i ścieżek, pliki konfiguracji,
 * których zmiana wymusza pełny zakres, oraz powód pominięcia. Pusta lista wyzwalaczy (`extensions` i `paths`)
 * oznacza „zawsze” (fail-closed). Decyzję o pominięciu podejmuje profil `local-narrow` (zob. `GateExecutor::outOfScope`).
 */
final readonly class GateTriggers
{
    /**
     * @param list<string> $extensions     rozszerzenia (bez kropki, wielkość liter ma znaczenie), które uruchamiają bramkę
     * @param list<string> $paths          ścieżki dokładne, prefiksy katalogów (kończące się `/`) i wzorce z `*` lub `?`
     * @param list<string> $fullScopeFiles pliki konfiguracji, których zmiana wymusza pełny zakres
     */
    public function __construct(
        public array $extensions = [],
        public array $paths = [],
        public array $fullScopeFiles = [],
        public string $skipReason = 'Zmienione pliki nie wymagają tej bramki.',
    ) {}

    /**
     * Czy którakolwiek zmieniona ścieżka uruchamia bramkę. Brak wyzwalaczy, nietypowa ścieżka (`\`, `..`, absolutna,
     * pusty segment), plik konfiguracji i plik bez rozszerzenia z listy dają `true`, a nie pominięcie.
     *
     * @param list<string> $changed
     */
    public function matches(array $changed): bool
    {
        if ($this->extensions === [] && $this->paths === []) {
            return true;
        }
        foreach ($changed as $path) {
            if (!(new PathSanity())->isSane($path) || in_array($path, $this->fullScopeFiles, true) || $this->triggeredBy($path)) {
                return true;
            }
        }

        return false;
    }

    private function triggeredBy(string $path): bool
    {
        if (in_array(pathinfo($path, PATHINFO_EXTENSION), $this->extensions, true)) {
            return true;
        }
        foreach ($this->paths as $pattern) {
            if ($this->matchesPattern($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    private function matchesPattern(string $pattern, string $path): bool
    {
        if (str_ends_with($pattern, '/')) {
            return str_starts_with($path, $pattern);
        }
        if (strpbrk($pattern, '*?') !== false) {
            return fnmatch($pattern, $path, FNM_PATHNAME);
        }

        return $pattern === $path;
    }
}
