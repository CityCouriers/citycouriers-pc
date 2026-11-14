<?php

declare(strict_types=1);

use CityCouriers\Pc\Tests\Support\Child;

/**
 * Uruchamia skrypt dolnych granic na plikach tymczasowych.
 *
 * @param array<string, string> $require
 * @param array<string, string> $installed
 * @param array<string, string> $latest
 * @param array<string, string> $exceptions
 *
 * @return array{int, string}
 */
function runBounds(array $require, array $installed, array $latest, array $exceptions = []): array
{
    $dir = sys_get_temp_dir() . '/pc-bounds-' . bin2hex(random_bytes(6));
    mkdir($dir);
    $packages = [];
    foreach ($installed as $name => $version) {
        $packages[] = ['name' => $name, 'version' => $version];
    }
    $files = [
        'composer.json' => ['require-dev' => $require],
        'installed.json' => ['packages' => $packages],
        'latest.json' => $latest,
        'exceptions.json' => (object) $exceptions,
    ];
    foreach ($files as $name => $data) {
        file_put_contents($dir . '/' . $name, json_encode($data, JSON_THROW_ON_ERROR));
    }
    [$exit, $stdout, $stderr] = Child::capture([
        PHP_BINARY,
        __DIR__ . '/../../scripts/ci/tool-bounds.php',
        '--manifest=' . $dir . '/composer.json',
        '--installed=' . $dir . '/installed.json',
        '--latest=' . $dir . '/latest.json',
        '--exceptions=' . $dir . '/exceptions.json',
    ]);
    foreach (array_keys($files) as $name) {
        unlink($dir . '/' . $name);
    }
    rmdir($dir);

    return [$exit, $stdout . $stderr];
}

it('dolna granica niższa niż zainstalowana wersja daje kod 1 i wskazuje pakiet (kontrola ujemna)', function (): void {
    [$exit, $output] = runBounds(
        ['pestphp/pest' => '^5.3.5', 'laravel/pint' => '^1.32.3'],
        ['pestphp/pest' => 'v5.3.6', 'laravel/pint' => 'v1.32.3'],
        ['pestphp/pest' => '5.3.6', 'laravel/pint' => '1.32.3'],
    );

    expect($exit)->toBe(1)
        ->and($output)->toContain('pestphp/pest: dolna granica 5.3.5 różna od najnowszej stabilnej 5.3.6')
        ->and($output)->not->toContain('laravel/pint');
});

it('dolna granica równa najnowszej stabilnej i zainstalowana wersja dają kod 0', function (): void {
    [$exit, $output] = runBounds(['pestphp/pest' => '^5.3.6'], ['pestphp/pest' => 'v5.3.6'], ['pestphp/pest' => '5.3.6']);

    expect($exit)->toBe(0)->and($output)->toContain('OK (1');
});

it('zainstalowana wersja starsza od najnowszej stabilnej daje kod 1', function (): void {
    [$exit, $output] = runBounds(['pestphp/pest' => '^5.3.6'], ['pestphp/pest' => 'v5.3.5'], ['pestphp/pest' => '5.3.6']);

    expect($exit)->toBe(1)->and($output)->toContain('pestphp/pest: zainstalowana 5.3.5 różna od najnowszej stabilnej 5.3.6');
});

it('wersja deweloperska bez wpisu wyjątku daje kod 1, z wpisem 0', function (): void {
    $require = ['phpmd/phpmd' => '3.x-dev'];
    $installed = ['phpmd/phpmd' => '3.x-dev'];
    $latest = ['phpmd/phpmd' => '3.0.0'];

    [$withoutException, $output] = runBounds($require, $installed, $latest);
    [$withException] = runBounds($require, $installed, $latest, ['phpmd/phpmd' => 'powód']);

    expect($withoutException)->toBe(1)
        ->and($output)->toContain('phpmd/phpmd: ograniczenie "3.x-dev" bez pewnej dolnej granicy')
        ->and($withException)->toBe(0);
});

it('pusty powód wyjątku nie zwalnia pakietu', function (): void {
    [$exit] = runBounds(['pestphp/pest' => '^5.3.5'], ['pestphp/pest' => '5.3.6'], ['pestphp/pest' => '5.3.6'], ['pestphp/pest' => ' ']);

    expect($exit)->toBe(1);
});

it('alternatywa w ograniczeniu liczy się po najniższej dolnej granicy', function (): void {
    [$exit, $output] = runBounds(['a/b' => '^1.2 || ^2.0'], ['a/b' => '2.0.0'], ['a/b' => '2.0.0']);

    expect($exit)->toBe(1)->and($output)->toContain('dolna granica 1.2.0 różna od najnowszej stabilnej 2.0.0');
});

it('brak najnowszej stabilnej wersji bez wyjątku daje kod 1', function (): void {
    [$exit, $output] = runBounds(['a/b' => '^1.0'], ['a/b' => '1.0.0'], []);

    expect($exit)->toBe(1)->and($output)->toContain('a/b: brak najnowszej stabilnej wersji');
});

it('wersja wstępna w ograniczeniu i zainstalowana, przy starszej stabilnej, daje kod 1', function (): void {
    [$exit, $output] = runBounds(['a/b' => '^6.0.0-beta1'], ['a/b' => '6.0.0-beta1'], ['a/b' => '5.3.5']);

    expect($exit)->toBe(1)->and($output)->toContain('a/b: ograniczenie "^6.0.0-beta1" bez pewnej dolnej granicy');
});

it('dolna granica wyższa niż najnowsza stabilna daje kod 1', function (): void {
    [$exit, $output] = runBounds(['a/b' => '^6.0'], ['a/b' => '6.0.0'], ['a/b' => '5.3.5']);

    expect($exit)->toBe(1)->and($output)->toContain('a/b: dolna granica 6.0.0 różna od najnowszej stabilnej 5.3.5');
});

it('niestabilna zainstalowana wersja przy poprawnej dolnej granicy daje kod 1', function (): void {
    [$exit, $output] = runBounds(['a/b' => '^5.3.5'], ['a/b' => '5.3.5-RC1'], ['a/b' => '5.3.5']);

    expect($exit)->toBe(1)->and($output)->toContain('a/b: zainstalowana 5.3.5-RC1 różna od najnowszej stabilnej 5.3.5 (albo niestabilna)');
});

it('ograniczenie z górną granicą, wieloczłonowe albo gwiazdka to nieobsługiwana składnia (kod 1)', function (string $constraint): void {
    [$exit, $output] = runBounds(['a/b' => $constraint], ['a/b' => '5.3.5'], ['a/b' => '5.3.5']);

    expect($exit)->toBe(1)->and($output)->toContain('a/b: ograniczenie "' . $constraint . '" bez pewnej dolnej granicy');
})->with(['>=5.3.5 <6.0', '>=5.3.5,<6.0', '5.*', '*', '^5.3.5@dev']);
