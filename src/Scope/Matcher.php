<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Jeden warunek dopasowania ścieżki (względnej, ze znakami `/`, wielkość liter ma znaczenie):
 * `exact`, `prefix`, `suffix` albo `regex` (PCRE bez ogranicznika, kotwiczony przez wzorzec).
 */
final readonly class Matcher
{
    public const array KINDS = ['exact', 'prefix', 'suffix', 'regex'];

    /**
     * @throws ScopeException
     */
    public function __construct(
        public string $kind,
        public string $value,
    ) {
        if (!in_array($kind, self::KINDS, true) || $value === '') {
            throw new ScopeException(sprintf('Niepoprawny warunek reguły zakresu: %s.', $kind));
        }
        if ($kind === 'regex' && (str_contains($value, '~') || (new Quiet())->call(fn(): int|false => preg_match($this->pattern(), '')) === false)) {
            throw new ScopeException(sprintf('Niepoprawne wyrażenie regularne reguły zakresu: %s.', $value));
        }
    }

    public function matches(string $path): bool
    {
        return match ($this->kind) {
            'exact' => $path === $this->value,
            'prefix' => str_starts_with($path, $this->value),
            'suffix' => str_ends_with($path, $this->value),
            default => preg_match($this->pattern(), $path) === 1,
        };
    }

    private function pattern(): string
    {
        return '~' . $this->value . '~D';
    }
}
