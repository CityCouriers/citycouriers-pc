<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

use JsonException;

/**
 * Wczytuje zestaw reguł z pliku JSON (`version: 1`). Każdy błąd to `ScopeException`, nigdy ciche pominięcie reguły.
 */
final class RuleSetLoader
{
    /**
     * @throws ScopeException
     */
    public function load(string $path): RuleSet
    {
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw new ScopeException(sprintf('Nie da się odczytać zestawu reguł zakresu: %s.', $path));
        }
        try {
            $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ScopeException('Zestaw reguł zakresu nie jest poprawnym JSON: ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($data) || ($data['version'] ?? null) !== 1) {
            throw new ScopeException('Zestaw reguł zakresu: wymagane `version: 1`.');
        }

        return new RuleSet(
            $this->strings($data['gates'] ?? null, 'gates'),
            $this->map($data['triggers'] ?? null),
            $this->rules($data['rules'] ?? null),
        );
    }

    /**
     * @return list<string>
     *
     * @throws ScopeException
     */
    private function strings(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new ScopeException(sprintf('Zestaw reguł zakresu: pole „%s” musi być listą tekstów.', $field));
        }
        $strings = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new ScopeException(sprintf('Zestaw reguł zakresu: pole „%s” musi być listą tekstów.', $field));
            }
            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * @return array<string, string>
     *
     * @throws ScopeException
     */
    private function map(mixed $value): array
    {
        if (!is_array($value)) {
            throw new ScopeException('Zestaw reguł zakresu: pole „triggers” musi być obiektem.');
        }
        $map = [];
        foreach ($value as $key => $item) {
            if (!is_string($item)) {
                throw new ScopeException('Zestaw reguł zakresu: opis wyzwalaczy musi być tekstem.');
            }
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * @return list<ScopeRule>
     *
     * @throws ScopeException
     */
    private function rules(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new ScopeException('Zestaw reguł zakresu: pole „rules” musi być listą.');
        }

        return array_map($this->rule(...), $value);
    }

    /**
     * @throws ScopeException
     */
    private function rule(mixed $data): ScopeRule
    {
        if (!is_array($data) || !is_string($data['name'] ?? null)) {
            throw new ScopeException('Zestaw reguł zakresu: reguła wymaga nazwy.');
        }
        $match = $data['match'] ?? null;
        if (!is_array($match) || array_diff(array_keys($match), Matcher::KINDS) !== []) {
            throw new ScopeException(sprintf('Zestaw reguł zakresu: reguła „%s” ma nieznany warunek.', $data['name']));
        }
        $matchers = [];
        foreach ($match as $kind => $values) {
            foreach ($this->strings($values, 'match') as $pattern) {
                $matchers[] = new Matcher((string) $kind, $pattern);
            }
        }

        return new ScopeRule($data['name'], $this->strings($data['gates'] ?? [], 'gates'), $matchers, ($data['fuse'] ?? false) === true ? RuleEffect::ForceFull : RuleEffect::Narrow);
    }
}
