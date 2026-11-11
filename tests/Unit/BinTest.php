<?php

declare(strict_types=1);

use CityCouriers\Pc\Tests\Support\Harness;
use CityCouriers\Pc\Version;

/**
 * Dowód z procesu: `bin/pc` uruchomiony jak przez `vendor/bin/pc` zwraca wersję i kody wyjścia.
 *
 * @return array{int, string, string}
 */
function runBin(string ...$arguments): array
{
    $command = [PHP_BINARY, __DIR__ . '/../../bin/pc', ...array_values($arguments)];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if ($process === false) {
        throw new RuntimeException('Nie udało się uruchomić bin/pc.');
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

it('bin/pc --version zwraca wersje i kod 0', function (): void {
    [$exit, $stdout] = runBin('--version');

    expect($exit)->toBe(0)->and($stdout)->toBe('pc ' . Version::CURRENT . "\n");
});

it('bin/pc dla nieznanego polecenia zwraca kod 2', function (): void {
    [$exit, , $stderr] = runBin('xyz');

    expect($exit)->toBe(2)->and($stderr)->toContain('Nieznane polecenie');
});

it('bin/pc config:validate zwraca kod 1 dla blednej konfiguracji', function (): void {
    [$exit, , $stderr] = runBin('config:validate', __DIR__ . '/../Fixtures/negative/invalid-pc.json');

    expect($exit)->toBe(1)->and($stderr)->toContain('Nieznana bramka');
});

it('bin/pc run bez pc.json zwraca kod 3 i poprawny dokument JSON (nigdy 0)', function (): void {
    [$exit, $stdout] = runBin('run', '--format=json', '--config=' . __DIR__ . '/../Fixtures/nie-ma.json');
    /** @var array<string, mixed> $document */
    $document = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(3)->and(Harness::at($document, 'status'))->toBe('config_error')->and(Harness::at($document, 'exit_code'))->toBe(3);
});

it('bin/pc run na przykładowym pc.json: kod procesu równa się exit_code, dokument zgodny ze schematem', function (): void {
    [$exit, $stdout] = runBin('run', '--format=json', '--config=' . __DIR__ . '/../Fixtures/valid-pc.json');
    /** @var array<string, mixed> $document */
    $document = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

    // Rejestr nie ma jeszcze żadnej bramki, więc włączone bramki to gate_not_implemented (kod 4, nigdy 0).
    expect($exit)->toBe(4)
        ->and(Harness::at($document, 'exit_code'))->toBe($exit)
        ->and(Harness::at($document, 'gates.0.reason.code'))->toBe('gate_not_implemented')
        ->and(Harness::schemaErrors($document))->toBe([]);
});

it('bin/pc run z nieznaną opcją zwraca kod 2', function (): void {
    [$exit, $stdout, $stderr] = runBin('run', '--nieznana');

    expect($exit)->toBe(2)->and($stdout)->toBe('')->and($stderr)->toContain('Nieznana opcja');
});
