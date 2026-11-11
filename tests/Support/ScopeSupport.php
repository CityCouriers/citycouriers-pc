<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Tests\Support;

use CityCouriers\Pc\Scope\RuleSet;
use CityCouriers\Pc\Scope\RuleSetLoader;

/**
 * Wspólne wejście testów zakresu: zestaw reguł tego repo (ten sam plik, którego używają hook i CI).
 */
final class ScopeSupport
{
    public static function rules(): RuleSet
    {
        return (new RuleSetLoader())->load(self::root() . '/scripts/quality/scope-rules.json');
    }

    /**
     * Zawęża wartość z datasetu Pesta (typ `array`) do listy napisów; element, który nie jest napisem, odpada,
     * więc test z błędną daną zobaczy krótszą listę zamiast cichego rzutowania.
     *
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    public static function strings(array $values): array
    {
        return array_values(array_filter($values, is_string(...)));
    }

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
