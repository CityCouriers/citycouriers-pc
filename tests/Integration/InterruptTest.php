<?php

declare(strict_types=1);

use CityCouriers\Pc\Tests\Support\Child;
use CityCouriers\Pc\Tests\Support\Harness;

/**
 * Rzeczywisty proces z sygnałem (integracyjny): przerwanie biegu to kod 130 i wykonane finalize.
 */
it('SIGINT w podprocesie: kod 130, finalize wykonane, ostateczny wynik w raporcie', function (): void {
    $marker = (string) tempnam(sys_get_temp_dir(), 'pcsig');
    $windows = PHP_OS_FAMILY === 'Windows';
    // POSIX: SIGINT z procesu testowego (pcntl). Windows: zdarzenie CTRL_BREAK nie ma adresata po PID, trafia do
    // wszystkich procesów konsoli, więc podproces dostaje WŁASNĄ konsolę i sam wysyła je sobie ("self-break").
    // Test nigdy nie wysyła zdarzenia do konsoli, w której biegnie Pest (zepsułoby tryb konsoli i urwało bieg).
    $arguments = [PHP_BINARY, __DIR__ . '/../Support/interrupt-run.php', $marker, ...($windows ? ['self-break'] : [])];
    $pipes = [];
    $process = Child::open($arguments, $pipes, null, null, $windows ? ['create_new_console' => true] : null);
    if ($process === false) {
        throw new RuntimeException('Nie udało się uruchomić podprocesu.');
    }

    // Czekamy, aż bramka w podprocesie zacznie pracę. Na Windows podproces sygnalizuje się sam zaraz po markerze, więc
    // marker może od razu przejść z "ready" do "finalized". Podproces zapisuje marker atomowo (zob. interrupt-run.php),
    // więc każdy odczyt to cała wartość: pusta (przed pierwszym zapisem), "ready" albo "finalized".
    $deadline = microtime(true) + 10;
    do {
        $seen = file_get_contents($marker);
        if (in_array($seen, ['ready', 'finalized'], true)) {
            break;
        }
        usleep(20_000);
    } while (microtime(true) < $deadline);
    expect($seen)->toBeIn(['ready', 'finalized']);
    if (!$windows) {
        proc_terminate($process, 2);
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $finalized = file_get_contents($marker);
    unlink($marker);
    /** @var array<string, mixed> $document */
    $document = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(130)
        ->and(Harness::at($document, 'status'))->toBe('interrupted')
        ->and(Harness::at($document, 'gates.0.diagnostics.0.message'))->toBe('finalize: interrupted')
        ->and($finalized)->toBe('finalized')
        ->and(Harness::schemaErrors($document))->toBe([]);
});
