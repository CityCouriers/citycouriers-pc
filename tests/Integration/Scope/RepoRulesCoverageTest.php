<?php

declare(strict_types=1);

use CityCouriers\Pc\Tests\Support\Child;
use CityCouriers\Pc\Tests\Support\ScopeSupport;

it('pliki repo bez reguły to świadomy wyjątek: nowy plik bez reguły wymusza decyzję', function (): void {
    [$code, $listing] = Child::capture(['git', '-C', ScopeSupport::root(), 'ls-files', '-z']);
    expect($code)->toBe(0);
    $files = array_filter(explode("\0", $listing));
    // Pliki ze stage'a, które jeszcze nie weszły do HEAD, też muszą mieć regułę (ls-files pokazuje indeks).
    expect($files)->not->toBe([]);
    $without = array_values(array_filter($files, static fn(string $file): bool => ScopeSupport::rules()->ruleFor($file) === null));

    expect($without)->toBe([]);
});
