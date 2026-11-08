<?php

declare(strict_types=1);

use CityCouriers\Pc\Application;
use CityCouriers\Pc\Version;

const FIXTURES = __DIR__ . '/../Fixtures';

it('wypisuje wersje', function (): void {
    $result = (new Application())->run(['--version']);

    expect($result->exitCode)->toBe(Application::EXIT_OK)
        ->and($result->stdout)->toBe('pc ' . Version::CURRENT . "\n");
});

it('bez argumentow wypisuje pomoc', function (): void {
    $result = (new Application())->run([]);

    expect($result->exitCode)->toBe(Application::EXIT_OK)
        ->and($result->stdout)->toContain('config:validate');
});

it('zna alias list dla pomocy', function (): void {
    $result = (new Application())->run(['list']);

    expect($result->stdout)->toContain('Polecenia:');
});

it('odrzuca nieznane polecenie kodem uzycia', function (): void {
    $result = (new Application())->run(['nie-ma-takiego']);

    expect($result->exitCode)->toBe(Application::EXIT_USAGE)
        ->and($result->stdout)->toBe('')
        ->and($result->stderr)->toContain('Nieznane polecenie: nie-ma-takiego');
});

it('przyjmuje poprawna konfiguracje', function (): void {
    $result = (new Application())->run(['config:validate', FIXTURES . '/valid-pc.json']);

    expect($result->exitCode)->toBe(Application::EXIT_OK)
        ->and($result->stdout)->toContain('Konfiguracja poprawna');
});

it('zatrzymuje bledna konfiguracje (kontrola ujemna)', function (): void {
    $result = (new Application())->run(['config:validate', FIXTURES . '/negative/invalid-pc.json']);

    expect($result->exitCode)->toBe(Application::EXIT_FAILURE)
        ->and($result->stderr)->toContain('Nieznana bramka: nieistniejaca');
});

it('zglasza brak pliku konfiguracji', function (): void {
    $result = (new Application())->run(['config:validate', FIXTURES . '/nie-ma.json']);

    expect($result->exitCode)->toBe(Application::EXIT_FAILURE)
        ->and($result->stderr)->toContain('Brak pliku konfiguracji');
});

it('zglasza niepoprawny JSON', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'pc');
    expect($path)->toBeString();
    file_put_contents((string) $path, '{ to nie jest json');

    $result = (new Application())->run(['config:validate', (string) $path]);
    unlink((string) $path);

    expect($result->exitCode)->toBe(Application::EXIT_FAILURE)
        ->and($result->stderr)->toContain('Niepoprawny JSON');
});

it('odrzuca konfiguracje bedaca lista', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'pc');
    expect($path)->toBeString();
    file_put_contents((string) $path, '[1, 2]');

    $result = (new Application())->run(['config:validate', (string) $path]);
    unlink((string) $path);

    expect($result->exitCode)->toBe(Application::EXIT_FAILURE)
        ->and($result->stderr)->toContain('musi zawierać obiekt JSON');
});
