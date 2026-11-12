<?php

declare(strict_types=1);

use CityCouriers\Pc\Tests\Support\ScopeSupport;
use CityCouriers\Pc\Scope\EnforcementPolicy;
use CityCouriers\Pc\Scope\Profile;
use CityCouriers\Pc\Scope\ProfileResolver;

/**
 * Polityka egzekwowania jest osobna od klasyfikacji: `enforce-all` (CI) niczego nie skraca, `local-narrow` (hook) pomija.
 *
 * @param list<string> $paths
 */
function scopeSelect(array $paths, Profile $profile): CityCouriers\Pc\Scope\Selection
{
    return (new EnforcementPolicy())->select(ScopeSupport::classify($paths), $profile, ScopeSupport::rules()->gates);
}

it('enforce-all: pełny zestaw i pusta lista pominiętych dla README.md, docs/bramki.md, docs/x.php i pustej listy', function (array $paths): void {
    $selection = scopeSelect(ScopeSupport::strings($paths), Profile::EnforceAll);

    expect($selection->gates)->toBe(ScopeSupport::rules()->gates)
        ->and($selection->skipped)->toBe([])
        ->and($selection->full)->toBeTrue();
})->with([
    'README.md' => [['README.md']],
    'docs/bramki.md' => [['docs/bramki.md']],
    'docs/x.php' => [['docs/x.php']],
    'pusta lista' => [[]],
]);

it('enforce-all: lista klasyfikatora jest tylko informacją, co lokalnie zostałoby pominięte', function (): void {
    $selection = scopeSelect(['README.md'], Profile::EnforceAll);

    expect($selection->wouldSkip)->not->toBe([])
        ->and(array_map(static fn($skip): string => $skip->gate, $selection->wouldSkip))->toContain('stan', 'process');
});

it('local-narrow: README.md pomija bramki z powodem (pozytyw), a niepewność nie pomija nic (negatyw)', function (): void {
    $narrow = scopeSelect(['README.md'], Profile::LocalNarrow);
    $unknown = scopeSelect(['nieznany.txt'], Profile::LocalNarrow);

    expect($narrow->gates)->toBe(['privacy'])
        ->and($narrow->skipped)->not->toBe([])
        ->and($narrow->wouldSkip)->toBe([])
        ->and($unknown->gates)->toBe(ScopeSupport::rules()->gates)
        ->and($unknown->skipped)->toBe([]);
});

it('brak lub nieznany profil zachowuje się jak enforce-all', function (?string $value): void {
    $profile = (new ProfileResolver())->resolve($value);

    expect($profile)->toBe(Profile::EnforceAll)
        ->and(scopeSelect(['README.md'], $profile)->gates)->toBe(ScopeSupport::rules()->gates);
})->with([null, '', 'local_narrow', 'LOCAL-NARROW', 'narrow', 'enforce_all', 'all']);

it('tylko dokładna nazwa local-narrow zwęża zakres', function (): void {
    expect((new ProfileResolver())->resolve('local-narrow'))->toBe(Profile::LocalNarrow)
        ->and((new ProfileResolver())->isKnown('enforce-all'))->toBeTrue()
        ->and((new ProfileResolver())->isKnown('local-narow'))->toBeFalse();
});
