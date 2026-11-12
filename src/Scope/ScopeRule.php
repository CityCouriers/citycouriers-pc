<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Jawne zwolnienie z bramek: plik pasujący do reguły uruchamia tylko bramki z `gates`. Reguła z efektem `ForceFull` oznacza zmianę
 * samego mechanizmu bramek i wymusza pełny zestaw.
 */
final readonly class ScopeRule
{
    /**
     * @param list<string>  $gates
     * @param list<Matcher> $matchers
     */
    public function __construct(
        public string $name,
        public array $gates,
        public array $matchers,
        public RuleEffect $effect = RuleEffect::Narrow,
    ) {}

    public function matches(string $path): bool
    {
        foreach ($this->matchers as $matcher) {
            if ($matcher->matches($path)) {
                return true;
            }
        }

        return false;
    }
}
