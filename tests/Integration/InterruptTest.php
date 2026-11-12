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
    // POSIX: SIGINT z procesu testowego (proc_terminate). Windows: zdarzenie CTRL_BREAK z numerem grupy procesów
    // trafia tylko do tej grupy, a nie do całej konsoli, jeśli podproces jest liderem własnej grupy (create_process_group,
    // numer grupy to jego PID). Nie potrzeba własnej konsoli (create_new_console otwierał migające okno), a zdarzenie
    // nie dociera do konsoli Pesta (bez numeru grupy, czyli 0, zepsułoby tryb konsoli i urwało bieg).
    $arguments = [PHP_BINARY, __DIR__ . '/../Support/interrupt-run.php', $marker];
    $pipes = [];
    $process = Child::open($arguments, $pipes, null, null, $windows ? ['create_process_group' => true] : null);
    if ($process === false) {
        throw new RuntimeException('Nie udało się uruchomić podprocesu.');
    }

    // Czekamy, aż bramka w podprocesie zacznie pracę, i dopiero wtedy wysyłamy sygnał, więc do tej chwili marker to
    // najwyżej "ready". Podproces zapisuje marker atomowo (zob. interrupt-run.php), więc każdy odczyt to cała wartość:
    // pusta (przed pierwszym zapisem) albo "ready".
    $deadline = microtime(true) + 10;
    do {
        $seen = file_get_contents($marker);
        if ($seen === 'ready') {
            break;
        }
        usleep(20_000);
    } while (microtime(true) < $deadline);
    expect($seen)->toBe('ready');
    if ($windows) {
        $pid = proc_get_status($process)['pid'];
        expect(sapi_windows_generate_ctrl_event(PHP_WINDOWS_EVENT_CTRL_BREAK, $pid))->toBeTrue();
    } else {
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
