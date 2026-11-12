<?php

declare(strict_types=1);

use CityCouriers\Pc\Dev\Mutation\Inventory;
use CityCouriers\Pc\Dev\Mutation\Target;

it('skrót pliku nie zależy od końców linii (ten sam na Windows i Linuksie)', function (): void {
    expect(Inventory::hash("a\r\nb\rc\n"))->toBe(Inventory::hash("a\nb\nc\n"))
        ->and(Inventory::hash("a\n"))->not->toBe(Inventory::hash("b\n"));
});

it('klasy, interfejsy, cechy i wyliczenia z pełną przestrzenią nazw; bez `::class` i klas anonimowych', function (): void {
    $source = <<<'PHP'
        <?php
        namespace Pc\Przyklad;
        final class A { public function b(): string { return A::class . (new class {})::class; } }
        interface I {}
        trait T {}
        enum E: string { case X = 'x'; }
        PHP;

    expect(Inventory::classes($source))->toBe(['Pc\Przyklad\A', 'Pc\Przyklad\I', 'Pc\Przyklad\T', 'Pc\Przyklad\E'])
        ->and(Inventory::classes("<?php\nfunction f(): void {}\n"))->toBe([]);
});

it('cele z src: wszystkie pliki PHP, posortowane, ze ścieżką względną i skrótem', function (): void {
    $root = dirname(__DIR__, 3);
    $targets = Inventory::scan($root, 'src');
    $paths = array_map(static fn(Target $target): string => $target->path, $targets);
    $sorted = $paths;
    sort($sorted);
    $version = array_combine($paths, $targets)['src/Version.php'];

    expect($paths)->toBe($sorted)
        ->and(count($paths))->toBe(count(glob($root . '/src/{,*/,*/*/}*.php', GLOB_BRACE) ?: []))
        ->and($version->classes)->toBe(['CityCouriers\Pc\Version'])
        ->and($version->sha256)->toBe(Inventory::hash((string) file_get_contents($root . '/src/Version.php')))
        ->and(Inventory::scan($root, 'src/Version.php'))->toEqual([$version]);
});

it('kontrola ujemna: nieistniejący cel to błąd, nie pusta lista', function (): void {
    Inventory::scan(dirname(__DIR__, 3), 'src/NieMaTakiegoKatalogu');
})->throws(RuntimeException::class, 'Nie ma katalogu celów mutacji: src/NieMaTakiegoKatalogu');
