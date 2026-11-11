<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Zestaw reguł zakresu: uporządkowana lista bramek, opis wyzwalaczy każdej z nich (do komunikatu o pominięciu)
 * i reguły sprawdzane po kolei (pierwsza pasująca decyduje).
 */
final readonly class RuleSet
{
    /**
     * @param list<string>          $gates    wszystkie bramki w kolejności uruchamiania
     * @param array<string, string> $triggers opis wyzwalaczy bramki
     * @param list<ScopeRule>       $rules
     *
     * @throws ScopeException
     */
    public function __construct(
        public array $gates,
        public array $triggers,
        public array $rules,
    ) {
        $this->assertConsistent();
    }

    public function ruleFor(string $path): ?ScopeRule
    {
        foreach ($this->rules as $rule) {
            if ($rule->matches($path)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @throws ScopeException
     */
    private function assertConsistent(): void
    {
        if ($this->gates === [] || count(array_unique($this->gates)) !== count($this->gates)) {
            throw new ScopeException('Zestaw reguł zakresu: lista bramek jest pusta albo ma duplikaty.');
        }
        foreach ($this->gates as $gate) {
            if (!isset($this->triggers[$gate]) || $this->triggers[$gate] === '') {
                throw new ScopeException(sprintf('Zestaw reguł zakresu: bramka „%s” nie ma opisu wyzwalaczy.', $gate));
            }
        }
        foreach ($this->rules as $rule) {
            if ($rule->matchers === [] || array_diff($rule->gates, $this->gates) !== []) {
                throw new ScopeException(sprintf('Zestaw reguł zakresu: reguła „%s” jest pusta albo wskazuje nieznaną bramkę.', $rule->name));
            }
        }
    }
}
