<?php

declare(strict_types=1);

use CityCouriers\Pc\Engine\Checked;
use CityCouriers\Pc\Engine\CheckedUnit;
use CityCouriers\Pc\Engine\Diagnostic;
use CityCouriers\Pc\Engine\EndCause;
use CityCouriers\Pc\Engine\GateContext;
use CityCouriers\Pc\Engine\GateMode;
use CityCouriers\Pc\Engine\GateResult;
use CityCouriers\Pc\Engine\GateStatus;
use CityCouriers\Pc\Engine\Reason;
use CityCouriers\Pc\Engine\ReasonCode;
use CityCouriers\Pc\Engine\ResultFactory;
use CityCouriers\Pc\Engine\Severity;
use CityCouriers\Pc\Tests\Support\EventLog;
use CityCouriers\Pc\Tests\Support\FakeGate;
use CityCouriers\Pc\Tests\Support\FakeGit;
use CityCouriers\Pc\Tests\Support\FakeInterrupt;
use CityCouriers\Pc\Tests\Support\Harness;
use CityCouriers\Pc\Tests\Support\MiniJsonSchema;

function contractGate(string $name, GateMode $mode = GateMode::Blocking, ?EventLog $log = null): FakeGate
{
    return new FakeGate($name, $mode, false, CheckedUnit::Checks, $log ?? new EventLog());
}

/**
 * Wszystkie kody wyjścia `pc run`, każdy jako scenariusz: [kod, status].
 *
 * @return array<string, array{Closure(): array{0: CityCouriers\Pc\CommandResult, 1: array<string, mixed>}, int, string}>
 */
function contractScenarios(): array
{
    return [
        '0 passed' => [fn(): array => Harness::runJson([contractGate('format')]), 0, 'passed'],
        '1 failed' => [fn(): array => Harness::runJson([contractGate('format')->fails()]), 1, 'failed'],
        '2 usage_error' => [fn(): array => Harness::runJson([contractGate('format')], ['--zla-opcja']), 2, 'usage_error'],
        '3 config_error' => [fn(): array => Harness::runJson([contractGate('format')], [], []), 3, 'config_error'],
        '4 incomplete' => [fn(): array => Harness::runJson([contractGate('format')->missingTool()]), 4, 'incomplete'],
        '5 error' => [fn(): array => Harness::runJson([contractGate('format')->does(fn(): GateResult => new GateResult(GateStatus::Skipped))]), 5, 'error'],
        '130 interrupted' => [function (): array {
            $interrupt = new FakeInterrupt();
            $gate = contractGate('format')->does(static function () use ($interrupt): GateResult {
                $interrupt->raise(2);

                return (new ResultFactory())->failed([new Diagnostic('x')], new Checked(CheckedUnit::Checks, 1));
            });

            return Harness::runJson([$gate], [], null, null, $interrupt);
        }, 130, 'interrupted'],
        '143 interrupted (SIGTERM)' => [function (): array {
            $interrupt = new FakeInterrupt();
            $interrupt->raise(15);

            return Harness::runJson([contractGate('format')], [], null, null, $interrupt);
        }, 143, 'interrupted'],
    ];
}

it('dla każdego kodu wyjścia: kod procesu, status w JSON i zgodność ze schematem', function (): void {
    foreach (contractScenarios() as $label => [$scenario, $code, $status]) {
        [$result, $document] = $scenario();

        expect($result->exitCode)->toBe($code, $label)
            ->and(Harness::at($document, 'exit_code'))->toBe($code, $label)
            ->and(Harness::at($document, 'status'))->toBe($status, $label)
            ->and(Harness::schemaErrors($document))->toBe([], $label)
            ->and(substr_count(rtrim($result->stdout, "\n"), "\n"))->toBe(0, $label . ': dokładnie jedna linia JSON');
    }
});

it('brak jakiejkolwiek włączonej bramki to kod 3, nie zielone 0', function (): void {
    [$result, $document] = Harness::runJson([contractGate('format')], [], []);

    expect($result->exitCode)->toBe(3)->and(Harness::at($document, 'error.message'))->toContain('Żadna bramka');
});

it('wszystkie pola są obecne, a brak danych to null (diagnostyka bez pliku, linii i kolumny)', function (): void {
    $gate = contractGate('format')->does(fn(): GateResult => (new ResultFactory())->failed([
        new Diagnostic('naruszenie całego repo', severity: Severity::Warning),
    ], new Checked(CheckedUnit::Checks, 1)));
    [, $document] = Harness::runJson([$gate]);

    expect(Harness::at($document, 'gates.0.diagnostics.0'))->toBe([
        'rule' => null,
        'file' => null,
        'line' => null,
        'column' => null,
        'severity' => 'warning',
        'message' => 'naruszenie całego repo',
        'hint' => null,
    ])
        ->and(Harness::at($document, 'gates.0.reason'))->toBeNull()
        ->and(array_keys($document))->toBe(['schema_version', 'tool', 'command', 'scope', 'diff_base', 'status', 'exit_code', 'complete', 'summary', 'gates', 'error'])
        ->and(Harness::at($document, 'error'))->toBeNull()
        ->and(Harness::schemaErrors($document))->toBe([]);
});

it('negatywne kontrole schematu: brak schema_version, stan spoza zbioru, brakujące pole i obce pole', function (): void {
    [, $good] = Harness::runJson([contractGate('format')]);

    $bad = [
        'bez schema_version' => Harness::without($good, 'schema_version'),
        'status spoza zbioru' => Harness::with($good, 'status', 'zielony'),
        'stan bramki spoza zbioru' => Harness::with($good, 'gates.0.status', 'ok'),
        'brak pola reason (null musi być jawny)' => Harness::without($good, 'gates.0.reason'),
        'obce pole' => Harness::with($good, 'dodatkowe', 1),
        'failed bez diagnostyk' => Harness::with($good, 'gates.0.status', 'failed'),
        'skipped bez powodu' => Harness::with($good, 'gates.0.status', 'skipped'),
        'kod wyjścia spoza zbioru' => Harness::with($good, 'exit_code', 7),
        'zła wersja schematu' => Harness::with($good, 'schema_version', 2),
    ];

    expect(Harness::schemaErrors($good))->toBe([]);
    foreach ($bad as $label => $document) {
        expect(Harness::schemaErrors($document))->not->toBe([], $label . ' powinien być odrzucony');
    }
});

it('walidator schematu odrzuca też zły typ i pusty dokument', function (): void {
    $schema = MiniJsonSchema::forRun();

    expect($schema->validate([]))->not->toBe([])
        ->and($schema->validate('tekst'))->not->toBe([]);
});

it('kolejność gates wynika z rejestru, nie z kolejności rejestracji ani pc.json', function (): void {
    [, $document] = Harness::runJson(
        [contractGate('tests'), contractGate('phpstan'), contractGate('format')],
        [],
        ['tests', 'format', 'phpstan'],
    );

    expect(array_keys(Harness::statuses($document)))->toBe(['format', 'phpstan', 'tests']);
});

it('diagnostics są posortowane: plik (null na początku), linia, kolumna, reguła, komunikat', function (): void {
    $gate = contractGate('format')->does(fn(): GateResult => (new ResultFactory())->failed([
        new Diagnostic('d', 'r', 'b.php', 2, 1),
        new Diagnostic('c', 'r', 'a.php', 10, 1),
        new Diagnostic('b', 'r', 'a.php', 9, 5),
        new Diagnostic('a', 'r', 'a.php', 9, 2),
        new Diagnostic('z', 'r2', 'a.php', 9, 2),
        new Diagnostic('repo', 'r'),
        new Diagnostic('plik bez linii', 'r', 'a.php'),
    ], new Checked(CheckedUnit::Checks, 1)));
    [, $document] = Harness::runJson([$gate]);

    $order = array_map(static fn(mixed $diagnostic): string => is_string($message = Harness::at((array) $diagnostic, 'message')) ? $message : '', (array) Harness::at($document, 'gates.0.diagnostics'));

    expect($order)->toBe(['repo', 'plik bez linii', 'a', 'z', 'b', 'c', 'd']);
});

it('dwa biegi na tym samym stanie dają identyczny JSON po wyzerowaniu duration_ms', function (): void {
    $build = static fn(): array => [contractGate('format')->fails('b'), contractGate('phpstan'), contractGate('phpcpd', GateMode::Reporting)->missingTool()];
    [, $first] = Harness::runJson($build());
    [, $second] = Harness::runJson($build());

    $zero = static function (array $document): array {
        foreach (array_keys((array) Harness::at($document, 'gates')) as $index) {
            expect(Harness::at($document, 'gates.' . $index . '.duration_ms'))->toBeInt();
            $document = Harness::with($document, 'gates.' . $index . '.duration_ms', 0);
        }

        return $document;
    };

    expect(json_encode($zero($first)))->toBe(json_encode($zero($second)));
});

it('bramka, która nie biegła, ma duration_ms null', function (): void {
    [, $document] = Harness::runJson([contractGate('format')], [], ['format', 'mutation']);

    expect(Harness::at($document, 'gates.1.duration_ms'))->toBeNull();
});

it('zgodność terminal / JSON / kod wyjścia na tym samym wyniku', function (): void {
    $scenarios = [
        'ok' => static fn(): array => [contractGate('format'), contractGate('phpstan')],
        'zatrzymana' => static fn(): array => [contractGate('format')->fails(), contractGate('phpstan')],
        'niepełna' => static fn(): array => [contractGate('format'), contractGate('phpstan')->missingTool()],
        'raportująca niepełna' => static fn(): array => [contractGate('format'), contractGate('phpcpd', GateMode::Reporting)->missingTool()],
        'raportująca z uwagami' => static fn(): array => [contractGate('format'), contractGate('phpcpd', GateMode::Reporting)->fails()],
    ];

    foreach ($scenarios as $label => $build) {
        [$json, $document] = Harness::runJson($build());
        $text = Harness::run($build());
        $lines = explode("\n", trim($text->stdout));
        $gateLines = array_values(array_filter($lines, static fn(string $line): bool => preg_match('~^[a-z-]+: ~', $line) === 1));
        $last = (string) end($lines);

        expect($text->exitCode)->toBe($json->exitCode, $label)
            ->and($text->exitCode)->toBe(Harness::at($document, 'exit_code'), $label)
            ->and(count($gateLines))->toBe(Harness::at($document, 'summary.gates'), $label);

        foreach (array_keys((array) Harness::at($document, 'gates')) as $index) {
            $status = Harness::at($document, 'gates.' . $index . '.status');
            $expectedLabel = match ($status) {
                'passed' => 'przeszła',
                'failed' => Harness::at($document, 'gates.' . $index . '.mode') === 'blocking' ? 'zatrzymała' : 'uwagi',
                'skipped' => 'pominięta',
                default => 'NIE PRZESKANOWANO',
            };
            expect($gateLines[$index])->toStartWith(Harness::text(Harness::at($document, 'gates.' . $index . '.name')) . ': ' . $expectedLabel, $label);
        }

        $isGreen = Harness::at($document, 'status') === 'passed' && Harness::at($document, 'complete') === true;
        expect(str_contains($text->stdout, 'OK'))->toBe($isGreen, $label)
            ->and(str_starts_with($last, 'OK'))->toBe($isGreen, $label);
    }
});

it('błędy 2, 3 i 5 w terminalu nie zawierają słowa OK', function (): void {
    $text = Harness::run([contractGate('format')->does(fn(): GateResult => new GateResult(GateStatus::Skipped))]);

    expect($text->exitCode)->toBe(5)->and($text->stdout)->not->toContain('OK')->and($text->stdout)->toContain('BŁĄD');
});

// --- faza finalize (reguła 7) ---

it('finalize: raz po biegu normalnym, w kolejności odwrotnej do rozpoczęcia, z przyczyną completed', function (): void {
    $log = new EventLog();
    Harness::runJson([contractGate('format', log: $log), contractGate('phpstan', log: $log), contractGate('phpmd', log: $log)]);

    expect($log->lines)->toBe([
        'run:format', 'run:phpstan', 'run:phpmd',
        'finalize:phpmd:completed', 'finalize:phpstan:completed', 'finalize:format:completed',
    ]);
});

it('finalize: wykonuje się też po wyjątku w innej bramce (przyczyna gate_exception)', function (): void {
    $log = new EventLog();
    $boom = contractGate('format', log: $log)->does(static function (): GateResult {
        throw new RuntimeException('wybuch');
    });
    [, $document] = Harness::runJson([$boom, contractGate('phpstan', log: $log)]);

    expect($log->lines)->toBe(['run:format', 'run:phpstan', 'finalize:phpstan:gate_exception', 'finalize:format:gate_exception'])
        ->and(Harness::at($document, 'gates.0.reason.code'))->toBe('gate_crashed');
});

it('finalize: po przerwaniu (kod 130) wykonuje się z przyczyną interrupted, a ostateczny wynik jest w raporcie', function (): void {
    $log = new EventLog();
    $interrupt = new FakeInterrupt();
    $first = contractGate('format', log: $log)
        ->does(static function () use ($interrupt): GateResult {
            $interrupt->raise(2);

            return (new ResultFactory())->failed([new Diagnostic('pierwotne')], new Checked(CheckedUnit::Checks, 1));
        })
        ->withFinalize(fn(GateContext $c, GateResult $r, EndCause $cause): GateResult => (new ResultFactory())->failed([new Diagnostic('po finalize: ' . $cause->value)], new Checked(CheckedUnit::Checks, 1)));
    [$result, $document] = Harness::runJson([$first, contractGate('phpstan', log: $log)], [], null, null, $interrupt);

    expect($result->exitCode)->toBe(130)
        ->and(Harness::at($document, 'status'))->toBe('interrupted')
        ->and(Harness::at($document, 'error.code'))->toBe('interrupted')
        ->and(Harness::at($document, 'complete'))->toBeFalse()
        ->and($log->lines)->toBe(['run:format', 'finalize:format:interrupted'])
        ->and(Harness::at($document, 'gates.0.diagnostics.0.message'))->toBe('po finalize: interrupted')
        ->and(Harness::at($document, 'gates.1.reason.code'))->toBe('interrupted')
        ->and($log->lines)->not->toContain('run:phpstan');
});

it('finalize: po błędzie silnika wykonuje się z przyczyną engine_error', function (): void {
    $log = new EventLog();
    $broken = contractGate('phpstan', log: $log)->does(fn(): GateResult => new GateResult(GateStatus::Skipped));
    [$result] = Harness::runJson([contractGate('format', log: $log), $broken]);

    expect($result->exitCode)->toBe(5)
        ->and($log->lines)->toBe(['run:format', 'run:phpstan', 'finalize:phpstan:engine_error', 'finalize:format:engine_error']);
});

it('finalize: pogorszenie passed → failed daje kod 1 i status failed', function (): void {
    $gate = contractGate('format')->withFinalize(fn(): GateResult => (new ResultFactory())->failed([new Diagnostic('konflikt po przywróceniu')], new Checked(CheckedUnit::Checks, 1)));
    [$result, $document] = Harness::runJson([$gate]);

    expect($result->exitCode)->toBe(1)
        ->and(Harness::at($document, 'status'))->toBe('failed')
        ->and(Harness::at($document, 'gates.0.status'))->toBe('failed')
        ->and(Harness::at($document, 'gates.0.diagnostics.0.message'))->toBe('konflikt po przywróceniu');
});

it('finalize: poprawa failed → passed jest odrzucona (kod 5)', function (): void {
    $gate = contractGate('format')->fails()->withFinalize(fn(): GateResult => (new ResultFactory())->passed(new Checked(CheckedUnit::Checks, 1)));
    [$result, $document] = Harness::runJson([$gate]);

    expect($result->exitCode)->toBe(5)
        ->and(Harness::at($document, 'status'))->toBe('error')
        ->and(Harness::at($document, 'error.message'))->toContain('poprawiła wynik')
        ->and(Harness::at($document, 'gates.0.status'))->toBe('failed');
});

it('finalize: wyjątek jest osobnym faktem (kod 5), pozostałe finalize się wykonują', function (): void {
    $log = new EventLog();
    $boom = static function (): GateResult {
        throw new RuntimeException('finalize padło');
    };
    $passing = contractGate('format', log: $log)->withFinalize($boom);
    $last = contractGate('phpmd', log: $log);
    [$result, $document] = Harness::runJson([$passing, $last]);

    expect($log->lines)->toBe(['run:format', 'run:phpmd', 'finalize:phpmd:completed', 'finalize:format:completed'])
        ->and($result->exitCode)->toBe(5)
        ->and(Harness::at($document, 'status'))->toBe('error')
        ->and(Harness::at($document, 'error.code'))->toBe('engine_error')
        ->and(Harness::at($document, 'error.message'))->toContain('finalize bramki „format”')->toContain('finalize padło')
        ->and(Harness::at($document, 'gates.0.status'))->toBe('not_scanned')
        ->and(Harness::at($document, 'gates.0.reason.code'))->toBe('gate_crashed')
        ->and(Harness::at($document, 'gates.1.status'))->toBe('passed')
        ->and(Harness::schemaErrors($document))->toBe([]);
});

it('finalize: Failed → wyjątek w finalize zostaje failed, ale awaria jest w diagnostykach i w błędzie (kod 5)', function (): void {
    $log = new EventLog();
    $boom = static function (): GateResult {
        throw new RuntimeException('przywrócenie padło');
    };
    $failing = contractGate('phpstan', log: $log)->fails('błąd typów')->withFinalize($boom);
    $last = contractGate('phpmd', log: $log);
    [$result, $document] = Harness::runJson([$failing, $last]);

    $messages = array_map(static fn(mixed $d): string => Harness::text(Harness::at((array) $d, 'message')), (array) Harness::at($document, 'gates.0.diagnostics'));
    $rules = array_map(static fn(mixed $d): mixed => Harness::at((array) $d, 'rule'), (array) Harness::at($document, 'gates.0.diagnostics'));

    expect($log->lines)->toBe(['run:phpstan', 'run:phpmd', 'finalize:phpmd:completed', 'finalize:phpstan:completed'])
        ->and(Harness::at($document, 'gates.0.status'))->toBe('failed')
        ->and($messages)->toContain('błąd typów')
        ->and(implode("\n", $messages))->toContain('przywrócenie padło')
        ->and($rules)->toContain('finalize_crashed')
        ->and($result->exitCode)->toBe(5)
        ->and(Harness::at($document, 'error.message'))->toContain('przywrócenie padło')
        ->and(Harness::at($document, 'gates.1.status'))->toBe('passed')
        ->and(Harness::schemaErrors($document))->toBe([]);
});

it('finalize: NotScanned → wyjątek w finalize daje jawne gate_crashed z wcześniejszym powodem (kod 5)', function (): void {
    $boom = static function (): GateResult {
        throw new RuntimeException('stash nie wrócił');
    };
    $missing = contractGate('phpstan')->missingTool()->withFinalize($boom);
    [$result, $document] = Harness::runJson([$missing]);

    expect(Harness::at($document, 'gates.0.status'))->toBe('not_scanned')
        ->and(Harness::at($document, 'gates.0.reason.code'))->toBe('gate_crashed')
        ->and(Harness::at($document, 'gates.0.reason.message'))->toContain('stash nie wrócił')->toContain('Brak narzędzia')
        ->and($result->exitCode)->toBe(5)
        ->and(Harness::at($document, 'error.message'))->toContain('stash nie wrócił')
        ->and(Harness::at($document, 'complete'))->toBeFalse()
        ->and($result->stderr)->toContain('stash nie wrócił')
        ->and(Harness::schemaErrors($document))->toBe([]);
});

it('walidator: ujemne checked.count to błąd silnika (kod 5), 0 i dodatnie zachowują dotychczasowe wyniki', function (): void {
    $negative = contractGate('format')->does(fn(): GateResult => (new ResultFactory())->passed(new Checked(CheckedUnit::Checks, -1)));
    [$result, $document] = Harness::runJson([$negative]);

    expect($result->exitCode)->toBe(5)
        ->and(Harness::at($document, 'error.message'))->toContain('ujemną liczbę');

    $zero = contractGate('format')->does(fn(): GateResult => (new ResultFactory())->passed(new Checked(CheckedUnit::Checks, 0)));
    [$zeroResult, $zeroDocument] = Harness::runJson([$zero]);
    expect($zeroResult->exitCode)->toBe(4)
        ->and(Harness::at($zeroDocument, 'gates.0.reason.code'))->toBe('nothing_checked');

    [$positiveResult] = Harness::runJson([contractGate('format')]);
    expect($positiveResult->exitCode)->toBe(0);
});

it('finalize: SIGINT → wyjątek w finalize zachowuje kod 130, a awaria jest w error, gates[] i na stderr', function (): void {
    $log = new EventLog();
    $interrupt = new FakeInterrupt();
    $first = contractGate('format', log: $log)
        ->does(static function () use ($interrupt): GateResult {
            $interrupt->raise(2);

            return (new ResultFactory())->passed(new Checked(CheckedUnit::Checks, 1));
        })
        ->withFinalize(static function (): GateResult {
            throw new RuntimeException('stash nie wrócił');
        });
    $other = contractGate('phpmd', log: $log);
    [$result, $document] = Harness::runJson([$first, $other], [], null, null, $interrupt);

    expect($result->exitCode)->toBe(130)
        ->and(Harness::at($document, 'status'))->toBe('interrupted')
        ->and(Harness::at($document, 'complete'))->toBeFalse()
        ->and(Harness::at($document, 'error.code'))->toBe('interrupted')
        ->and(Harness::at($document, 'error.message'))->toContain('SIGINT')->toContain('stash nie wrócił')
        ->and($result->stderr)->toContain('SIGINT')->toContain('stash nie wrócił')
        ->and(Harness::at($document, 'gates.0.reason.code'))->toBe('gate_crashed')
        ->and(Harness::at($document, 'gates.0.reason.message'))->toContain('stash nie wrócił')
        ->and($log->lines)->toBe(['run:format', 'finalize:format:interrupted'])
        ->and(Harness::schemaErrors($document))->toBe([]);
});

it('finalize: błąd silnika → wyjątek w finalize: obie przyczyny w error i na stderr, pozostałe finalize biegną', function (): void {
    $log = new EventLog();
    $boom = static function (): GateResult {
        throw new RuntimeException('finalize padło');
    };
    $first = contractGate('format', log: $log)->withFinalize($boom);
    $broken = contractGate('phpstan', log: $log)->does(fn(): GateResult => new GateResult(GateStatus::Skipped));
    [$result, $document] = Harness::runJson([$first, $broken]);

    expect($result->exitCode)->toBe(5)
        ->and(Harness::at($document, 'complete'))->toBeFalse()
        ->and(Harness::at($document, 'error.message'))->toContain('wymaga powodu')->toContain('finalize padło')
        ->and($result->stderr)->toContain('finalize padło')
        ->and(Harness::at($document, 'gates.0.reason.code'))->toBe('gate_crashed')
        ->and($log->lines)->toBe(['run:format', 'run:phpstan', 'finalize:phpstan:engine_error', 'finalize:format:engine_error'])
        ->and(Harness::schemaErrors($document))->toBe([]);
});

it('walidator: ujemne checked.count w not_scanned i skipped też to kod 5, a dokument zgodny ze schematem', function (): void {
    $notScanned = contractGate('format')->does(fn(): GateResult => new GateResult(GateStatus::NotScanned, new Reason(ReasonCode::ToolMissing, 'brak'), new Checked(CheckedUnit::Checks, -1)));
    [$result, $document] = Harness::runJson([$notScanned]);

    expect($result->exitCode)->toBe(5)
        ->and(Harness::at($document, 'error.message'))->toContain('ujemną liczbę')
        ->and(Harness::schemaErrors($document))->toBe([]);

    $skipped = contractGate('format')->does(fn(): GateResult => new GateResult(GateStatus::Skipped, new Reason(ReasonCode::NoMatchingFiles, 'brak plików'), new Checked(CheckedUnit::Checks, -1)));
    [$skippedResult] = Harness::runJson([$skipped]);
    expect($skippedResult->exitCode)->toBe(5);
});

it('walidator: diagnostyka z linią lub kolumną poniżej 1 to kod 5, a dokument zgodny ze schematem', function (): void {
    foreach ([[0, 1], [1, -1], [-3, null]] as [$line, $column]) {
        $gate = contractGate('format')->does(fn(): GateResult => (new ResultFactory())->failed([new Diagnostic('zła pozycja', line: $line, column: $column)], new Checked(CheckedUnit::Checks, 1)));
        [$result, $document] = Harness::runJson([$gate]);

        expect($result->exitCode)->toBe(5)
            ->and(Harness::at($document, 'error.message'))->toContain('od 1')
            ->and(Harness::schemaErrors($document))->toBe([]);
    }
});

it('duration_ms nie wlicza czasu innych bramek między fazą główną a finalize', function (): void {
    $fast = contractGate('format')->withFinalize(fn(GateContext $c, GateResult $r): GateResult => $r);
    $slow = contractGate('phpstan')->does(function (): GateResult {
        usleep(250_000);

        return (new ResultFactory())->passed(new Checked(CheckedUnit::Checks, 1));
    });
    [, $document] = Harness::runJson([$fast, $slow]);

    expect(Harness::at($document, 'gates.0.duration_ms'))->toBeLessThan(100)
        ->and(Harness::at($document, 'gates.1.duration_ms'))->toBeGreaterThanOrEqual(200);
});

it('finalize: bramka, której faza główna się nie rozpoczęła, go nie dostaje', function (): void {
    $log = new EventLog();
    $pre = new FakeGate('env', GateMode::Blocking, true, CheckedUnit::Checks, $log);
    $pre->fails();
    Harness::runJson([$pre, contractGate('format', log: $log)]);

    expect($log->lines)->toBe(['run:env', 'finalize:env:completed'])
        ->and($log->lines)->not->toContain('finalize:format:completed');
});

it('finalize: sygnał w trakcie finalize jest odroczony, pozostałe finalize się wykonują', function (): void {
    $log = new EventLog();
    $interrupt = new FakeInterrupt();
    $first = contractGate('format', log: $log);
    $second = contractGate('phpstan', log: $log)->withFinalize(static function (GateContext $c, GateResult $r) use ($interrupt): GateResult {
        $interrupt->raise(2);

        return $r;
    });
    [$result, $document] = Harness::runJson([$first, $second], [], null, null, $interrupt);

    expect($log->lines)->toBe(['run:format', 'run:phpstan', 'finalize:phpstan:completed', 'finalize:format:completed'])
        ->and($result->exitCode)->toBe(130)
        ->and(Harness::at($document, 'status'))->toBe('interrupted');
});

// --- przerwanie ---

it('przerwanie w trakcie bramki: nieuruchomione bramki są not_scanned / interrupted, wynik passed niewiarygodny', function (): void {
    $log = new EventLog();
    $interrupt = new FakeInterrupt();
    $first = contractGate('format', log: $log)->does(static function () use ($interrupt): GateResult {
        $interrupt->raise(2);

        return (new ResultFactory())->passed(new Checked(CheckedUnit::Checks, 1));
    });
    [$result, $document] = Harness::runJson([$first, contractGate('phpstan', log: $log), contractGate('phpmd', log: $log)], [], null, null, $interrupt);

    expect($result->exitCode)->toBe(130)
        ->and(Harness::statuses($document))->toBe(['format' => 'not_scanned', 'phpstan' => 'not_scanned', 'phpmd' => 'not_scanned'])
        ->and(Harness::at($document, 'gates.0.reason.code'))->toBe('interrupted')
        ->and(Harness::at($document, 'summary.passed'))->toBe(0)
        ->and(Harness::schemaErrors($document))->toBe([]);
});

it('SIGTERM daje kod 143', function (): void {
    $interrupt = new FakeInterrupt();
    $interrupt->raise(15);
    [$result, $document] = Harness::runJson([contractGate('format')], [], null, null, $interrupt);

    expect($result->exitCode)->toBe(143)->and(Harness::at($document, 'exit_code'))->toBe(143)->and(Harness::at($document, 'error.message'))->toContain('SIGTERM');
});

// --- rzeczywisty proces ---

it('SIGINT w podprocesie: kod 130, finalize wykonane, ostateczny wynik w raporcie', function (): void {
    $marker = (string) tempnam(sys_get_temp_dir(), 'pcsig');
    $process = proc_open([PHP_BINARY, __DIR__ . '/../Support/interrupt-run.php', $marker], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if ($process === false) {
        throw new RuntimeException('Nie udało się uruchomić podprocesu.');
    }

    // Czekamy, aż bramka w podprocesie zacznie pracę, i wysyłamy SIGINT.
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline && file_get_contents($marker) !== 'ready') {
        usleep(20_000);
    }
    expect(file_get_contents($marker))->toBe('ready');
    proc_terminate($process, 2);

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
})->skip(!function_exists('pcntl_signal') || DIRECTORY_SEPARATOR === '\\', 'Wymaga pcntl na systemie typu POSIX.');

it('FakeGit nie jest potrzebny w pełnym biegu: pełny zakres nie dotyka gita', function (): void {
    [$result, $document] = Harness::runJson([contractGate('format')], [], null, new FakeGit(repository: false));

    expect($result->exitCode)->toBe(0)->and(Harness::at($document, 'scope'))->toBe('full');
});
