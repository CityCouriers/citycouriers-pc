<?php

declare(strict_types=1);

use CityCouriers\Pc\Config\ConfigValidator;

/**
 * @param array<mixed> $config
 *
 * @return list<string>
 */
function validate(array $config): array
{
    return (new ConfigValidator())->validate($config);
}

it('przyjmuje minimalna konfiguracje', function (): void {
    expect(validate(['version' => 1]))->toBe([]);
});

it('wymaga pola version', function (): void {
    expect(validate([]))->toBe(['Pole "version" jest wymagane i musi mieć wartość 1.']);
});

it('odrzuca nieznany klucz glowny', function (): void {
    expect(validate(['version' => 1, 'inne' => true]))->toBe(['Nieznany klucz: inne']);
});

it('odrzuca nieznany standard', function (): void {
    expect(validate(['version' => 1, 'standard' => 'eslint']))->toHaveCount(1)
        ->and(validate(['version' => 1, 'standard' => ['pint']]))->toHaveCount(1);
});

it('przyjmuje oba standardy', function (): void {
    expect(validate(['version' => 1, 'standard' => 'pint']))->toBe([])
        ->and(validate(['version' => 1, 'standard' => 'phpcs']))->toBe([]);
});

it('odrzuca sciezki niemobilne', function (string $path): void {
    $errors = validate(['version' => 1, 'paths' => ['src' => [$path]]]);

    expect($errors)->toBe(['"paths.src": ścieżka musi być względna, bez "..".']);
})->with(['/abs/path', 'C:/Windows', '../poza', 'a\\b', '']);

it('wymaga niepustej listy sciezek', function (): void {
    expect(validate(['version' => 1, 'paths' => ['src' => []]]))->toHaveCount(1)
        ->and(validate(['version' => 1, 'paths' => ['src' => 'src']]))->toHaveCount(1)
        ->and(validate(['version' => 1, 'paths' => ['inne' => ['x']]]))->toBe(['Nieznany klucz w "paths": inne']);
});

it('sprawdza nazwy i typy bramek', function (): void {
    $errors = validate(['version' => 1, 'gates' => ['phpstan' => 'tak', 'nie-ma' => true]]);

    expect($errors)->toBe([
        '"gates.phpstan" musi być wartością true albo false.',
        'Nieznana bramka: nie-ma',
    ]);
});

it('sprawdza zestawy regul', function (): void {
    $errors = validate(['version' => 1, 'rulesets' => ['phpmd' => '/abs.xml', 'inny' => 'a.xml']]);

    expect($errors)->toBe([
        '"rulesets.phpmd": ścieżka musi być względna, bez "..".',
        'Nieznany zestaw reguł: inny',
    ]);
});

it('sprawdza moduly', function (): void {
    $errors = validate(['version' => 1, 'modules' => ['laravel' => 1, 'drupal' => true]]);

    expect($errors)->toBe([
        '"modules.laravel" musi być wartością true albo false.',
        'Nieznany moduł: drupal',
    ]);
});

it('wymaga, by sekcje byly obiektami', function (string $section): void {
    expect(validate(['version' => 1, $section => ['a']]))->toBe([sprintf('Pole "%s" musi być obiektem.', $section)])
        ->and(validate(['version' => 1, $section => 'x']))->toBe([sprintf('Pole "%s" musi być obiektem.', $section)]);
})->with(['paths', 'gates', 'rulesets', 'modules']);

it('przyjmuje pusta sekcje jako obiekt', function (): void {
    expect(validate(['version' => 1, 'gates' => [], 'modules' => []]))->toBe([]);
});
