<?php

declare(strict_types=1);

use CityCouriers\Pc\Process\EmptyInput;
use CityCouriers\Pc\Scope\SubProcess;
use CityCouriers\Pc\Tests\Support\Child;

/**
 * Proces potomny dostaje pusty stdin, nie stdin Pesta (terminal): czytanie z niego kończy się od razu zerem bajtów.
 */
it('deskryptor stdin to puste urządzenie', function (): void {
    expect((new EmptyInput())->descriptor())->toBe(['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r']);
});

it('SubProcess (kod pakietu): proces czytający stdin nie czeka i dostaje 0 bajtów', function (): void {
    $started = microtime(true);
    $output = (new SubProcess())->run([PHP_BINARY, '-r', 'echo strlen((string) stream_get_contents(STDIN));']);

    expect($output)->not->toBeNull()
        ->and($output?->stdout)->toBe('0')
        ->and(microtime(true) - $started)->toBeLessThan(5.0);
});

it('Child (testy): proces czytający stdin nie czeka i dostaje 0 bajtów', function (): void {
    [$code, $stdout] = Child::capture([PHP_BINARY, '-r', 'echo strlen((string) stream_get_contents(STDIN));']);

    expect($code)->toBe(0)->and($stdout)->toBe('0');
});
