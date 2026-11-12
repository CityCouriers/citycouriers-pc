<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Jedna logika klasyfikacji plików dla hooka i CI (#40): lista ścieżek i flagi na wejściu, decyzja na wyjściu. Czysta:
 * bez wejścia/wyjścia, bez wiedzy o hooku, CI ani profilu egzekwowania.
 *
 * Allowlista zwolnień, fail-closed: plik bez reguły, pusta lista, nietypowa ścieżka, dowiązanie symboliczne, submoduł
 * i zmiana mechanizmu dają pełny zestaw bramek.
 */
final readonly class Classifier
{
    public function __construct(private RuleSet $rules) {}

    /**
     * @param ParsedDiff $diff zmienione ścieżki (dodane, zmienione, usunięte) i informacja o dowiązaniu albo submodule
     */
    public function classify(ParsedDiff $diff): Classification
    {
        $paths = $diff->paths;
        if ($paths === []) {
            return $this->full(FullCause::EmptyList, 'pusta lista plików: pusty stage nie oznacza braku bramek');
        }
        if ($diff->specialModes) {
            return $this->full(FullCause::SpecialMode, 'dowiązanie symboliczne albo submoduł w zmianie');
        }

        $selected = [];
        foreach ($paths as $path) {
            if (!(new PathSanity())->isSane($path)) {
                return $this->full(FullCause::OddPath, sprintf('nietypowa ścieżka: %s', json_encode($path, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)));
            }
            $rule = $this->rules->ruleFor($path);
            if ($rule === null) {
                return $this->full(FullCause::NoRule, sprintf('brak reguły dla pliku %s', $path));
            }
            if ($rule->effect === RuleEffect::ForceFull) {
                return $this->full(FullCause::Mechanism, sprintf('zmiana mechanizmu bramek: %s', $path));
            }
            foreach ($rule->gates as $gate) {
                $selected[$gate] = true;
            }
        }

        return $this->narrow($selected);
    }

    /**
     * Pełny zestaw na żądanie wywołującego (np. `PC_HOOK_SCOPE=all`) albo gdy listy plików nie da się ustalić.
     */
    public function forced(FullCause $cause, string $reason): Classification
    {
        return $this->full($cause, $reason);
    }

    /**
     * @return list<string> wszystkie bramki zestawu reguł
     */
    public function gates(): array
    {
        return $this->rules->gates;
    }

    /**
     * @param array<string, true> $selected
     */
    private function narrow(array $selected): Classification
    {
        $gates = array_values(array_filter($this->rules->gates, static fn(string $gate): bool => isset($selected[$gate])));
        $skipped = [];
        foreach ($this->rules->gates as $gate) {
            if (!isset($selected[$gate])) {
                $skipped[] = new Skip($gate, sprintf('brak plików wyzwalających (%s)', $this->rules->triggers[$gate]));
            }
        }

        return new Classification($gates, $skipped, false, FullCause::None, 'zakres z reguł');
    }

    private function full(FullCause $cause, string $reason): Classification
    {
        return new Classification($this->rules->gates, [], true, $cause, $reason);
    }
}
