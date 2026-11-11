<?php

declare(strict_types=1);

use CityCouriers\Pc\Application;
use CityCouriers\Pc\Engine\Checked;
use CityCouriers\Pc\Engine\CheckedUnit;
use CityCouriers\Pc\Engine\Diagnostic;
use CityCouriers\Pc\Engine\EngineException;
use CityCouriers\Pc\Engine\Gate;
use CityCouriers\Pc\Engine\GateContext;
use CityCouriers\Pc\Engine\GateMode;
use CityCouriers\Pc\Engine\GateRegistry;
use CityCouriers\Pc\Engine\GateResult;
use CityCouriers\Pc\Engine\GateStatus;
use CityCouriers\Pc\Engine\ReasonCode;
use CityCouriers\Pc\Engine\ResultFactory;
use CityCouriers\Pc\Tests\Support\EventLog;
use CityCouriers\Pc\Tests\Support\FakeGate;
use CityCouriers\Pc\Tests\Support\FakeGit;
use CityCouriers\Pc\Tests\Support\Harness;

function engineGate(string $name, GateMode $mode = GateMode::Blocking, bool $precondition = false, ?EventLog $log = null): FakeGate
{
    return new FakeGate($name, $mode, $precondition, CheckedUnit::Checks, $log ?? new EventLog());
}

it('kontrola ujemna: blokująca bramka, która zawsze zatrzymuje, daje kod 1 z nazwą i diagnostyką', function (): void {
    [$result, $document] = Harness::runJson([engineGate('format')->fails('celowy błąd stylu')]);

    expect($result->exitCode)->toBe(1)
        ->and(Harness::at($document, 'exit_code'))->toBe(1)
        ->and(Harness::at($document, 'status'))->toBe('failed')
        ->and($result->stdout)->toContain('format')->toContain('celowy błąd stylu');

    $text = Harness::run([engineGate('format')->fails('celowy błąd stylu')]);
    expect($text->exitCode)->toBe(1)
        ->and($text->stdout)->toContain('format: zatrzymała')->toContain('celowy błąd stylu')->toContain('src/Zly.php:3:1');
});

it('kontrola ujemna: brak narzędzia daje not_scanned / tool_missing z instrukcją i kod 4, nigdy 0', function (): void {
    [$result, $document] = Harness::runJson([engineGate('phpstan')->missingTool()]);

    /** @var list<array<string, mixed>> $gates */
    $gates = Harness::at($document, 'gates');
    /** @var array<string, mixed> $reason */
    $reason = $gates[0]['reason'];

    expect($result->exitCode)->toBe(4)
        ->and(Harness::at($document, 'status'))->toBe('incomplete')
        ->and(Harness::at($document, 'complete'))->toBeFalse()
        ->and($gates[0]['status'])->toBe('not_scanned')
        ->and($reason['code'])->toBe('tool_missing')
        ->and($reason['message'])->toContain('Zainstaluj');
});

dataset('mixed results', [
    'passed + failed' => [fn(EventLog $l): array => [engineGate('format'), engineGate('phpstan')->fails()], 1, 'failed', ['passed' => 1, 'failed' => 1, 'not_scanned' => 0, 'blocking_failed' => 1], true],
    'failed + not_scanned' => [fn(EventLog $l): array => [engineGate('format')->fails(), engineGate('phpstan')->missingTool()], 1, 'failed', ['failed' => 1, 'not_scanned' => 1, 'blocking_failed' => 1], false],
    'passed + not_scanned' => [fn(EventLog $l): array => [engineGate('format'), engineGate('phpstan')->missingTool()], 4, 'incomplete', ['passed' => 1, 'not_scanned' => 1], false],
    'blocking passed + reporting failed' => [fn(EventLog $l): array => [engineGate('format'), engineGate('phpcpd', GateMode::Reporting)->fails()], 0, 'passed', ['passed' => 1, 'failed' => 1, 'reporting_failed' => 1, 'blocking_failed' => 0], true],
    'blocking passed + reporting not_scanned' => [fn(EventLog $l): array => [engineGate('format'), engineGate('phpcpd', GateMode::Reporting)->missingTool()], 0, 'passed', ['passed' => 1, 'not_scanned' => 1], false],
    'blocking failed + reporting failed' => [fn(EventLog $l): array => [engineGate('format')->fails(), engineGate('phpcpd', GateMode::Reporting)->fails()], 1, 'failed', ['failed' => 2, 'blocking_failed' => 1, 'reporting_failed' => 1], true],
    'blocking passed + blocking skipped' => [fn(EventLog $l): array => [engineGate('format'), engineGate('phpstan')->does(fn(): GateResult => (new ResultFactory())->skipped(ReasonCode::NoMatchingFiles, 'Same pliki dokumentacji.'))], 0, 'passed', ['passed' => 1, 'skipped' => 1], true],
    'precondition failed + next gates' => [fn(EventLog $l): array => [engineGate('env', GateMode::Blocking, true)->fails('zła wersja PHP'), engineGate('format', log: $l), engineGate('phpstan', log: $l)], 1, 'failed', ['failed' => 1, 'not_scanned' => 2], false],
    'precondition not_scanned + next gates' => [fn(EventLog $l): array => [engineGate('env', GateMode::Blocking, true)->missingTool(), engineGate('format', log: $l)], 4, 'incomplete', ['not_scanned' => 2], false],
]);

it('wyniki mieszane: kod, status, summary i complete zgodnie z regułą pierwszeństwa', function (Closure $build, int $code, string $status, array $summary, bool $complete): void {
    $log = new EventLog();
    /** @var list<Gate> $gates */
    $gates = $build($log);
    [$result, $document] = Harness::runJson($gates);

    expect($result->exitCode)->toBe($code)
        ->and(Harness::at($document, 'exit_code'))->toBe($code)
        ->and(Harness::at($document, 'status'))->toBe($status)
        ->and(Harness::at($document, 'complete'))->toBe($complete)
        ->and(Harness::schemaErrors($document))->toBe([]);

    foreach ($summary as $key => $value) {
        expect(Harness::at($document, 'summary.' . $key))->toBe($value);
    }
})->with('mixed results');

it('awaria runnera po pierwszej bramce: kod 5, wykonane bramki w raporcie, complete false', function (): void {
    $log = new EventLog();
    $broken = engineGate('phpstan')->does(fn(): GateResult => new GateResult(GateStatus::Skipped));
    [$result, $document] = Harness::runJson([engineGate('format', log: $log), $broken, engineGate('phpmd', log: $log)]);

    expect($result->exitCode)->toBe(5)
        ->and(Harness::at($document, 'status'))->toBe('error')
        ->and(Harness::at($document, 'complete'))->toBeFalse()
        ->and(Harness::at($document, 'error.code'))->toBe('engine_error')
        ->and(Harness::statuses($document))->toBe(['format' => 'passed', 'phpstan' => 'not_scanned', 'phpmd' => 'not_scanned'])
        ->and(Harness::at($document, 'gates.2.reason.code'))->toBe('interrupted')
        ->and($log->lines)->not->toContain('run:phpmd')
        ->and(Harness::schemaErrors($document))->toBe([]);
});

it('błąd odczytu stage\'a to kod 5 z poprawnym dokumentem', function (): void {
    [$result, $document] = Harness::runJson([engineGate('format')], ['--staged'], null, new FakeGit(stageBroken: true));

    expect($result->exitCode)->toBe(5)
        ->and(Harness::at($document, 'status'))->toBe('error')
        ->and(Harness::at($document, 'scope'))->toBe('staged')
        ->and(Harness::at($document, 'complete'))->toBeFalse()
        ->and(Harness::schemaErrors($document))->toBe([]);
});

it('bramka raportująca z naruszeniami nie zmienia kodu 0 i ma status failed oraz mode reporting', function (): void {
    [$result, $document] = Harness::runJson([engineGate('format'), engineGate('phpcpd', GateMode::Reporting)->fails('duplikat')]);

    expect($result->exitCode)->toBe(0)
        ->and(Harness::at($document, 'gates.1.status'))->toBe('failed')
        ->and(Harness::at($document, 'gates.1.mode'))->toBe('reporting')
        ->and(Harness::at($document, 'gates.1.diagnostics.0.message'))->toBe('duplikat');

    $text = Harness::run([engineGate('format'), engineGate('phpcpd', GateMode::Reporting)->fails('duplikat')]);
    expect($text->stdout)->toContain('phpcpd: uwagi');
    expect($text->stdout)->not->toContain('phpcpd: zatrzymała');
});

it('bramka raportująca not_scanned daje kod 0, complete false i NIEPEŁNY w ostatniej linii (nie OK)', function (): void {
    $gates = static fn(): array => [engineGate('format'), engineGate('phpcpd', GateMode::Reporting)->missingTool()];
    [$result, $document] = Harness::runJson($gates());
    $text = Harness::run($gates());
    $lines = explode("\n", trim($text->stdout));
    $last = end($lines);

    expect($result->exitCode)->toBe(0)
        ->and(Harness::at($document, 'complete'))->toBeFalse()
        ->and($text->exitCode)->toBe(0)
        ->and($last)->toStartWith('NIEPEŁNY')->toContain('phpcpd')
        ->and($text->stdout)->not->toContain('OK');
});

it('wyjątek w bramce to gate_crashed, a reszta bramek biegnie dalej', function (): void {
    $log = new EventLog();
    $boom = engineGate('format')->does(static function (): GateResult {
        throw new RuntimeException('wybuch');
    });
    [$result, $document] = Harness::runJson([$boom, engineGate('phpstan', log: $log)]);

    expect($result->exitCode)->toBe(4)
        ->and(Harness::at($document, 'gates.0.reason.code'))->toBe('gate_crashed')
        ->and(Harness::at($document, 'gates.0.reason.message'))->toContain('wybuch')
        ->and(Harness::statuses($document))->toBe(['format' => 'not_scanned', 'phpstan' => 'passed'])
        ->and($log->lines)->toBe(['run:phpstan', 'finalize:phpstan:gate_exception']);
});

it('bramka passed z checked.count == 0 jest zamieniana na not_scanned / nothing_checked', function (): void {
    $empty = engineGate('tests')->does(fn(): GateResult => (new ResultFactory())->passed(new Checked(CheckedUnit::Checks, 0)));
    [$result, $document] = Harness::runJson([$empty]);

    expect($result->exitCode)->toBe(4)
        ->and(Harness::at($document, 'gates.0.status'))->toBe('not_scanned')
        ->and(Harness::at($document, 'gates.0.reason.code'))->toBe('nothing_checked');
});

it('bramka bez jednostki (jawny brak) z checked null może przejść', function (): void {
    $noUnit = new FakeGate('secrets', unit: null);
    $noUnit->does(fn(): GateResult => (new ResultFactory())->passed());
    [$result, $document] = Harness::runJson([$noUnit]);

    expect($result->exitCode)->toBe(0)->and(Harness::at($document, 'gates.0.checked'))->toBeNull();
});

it('nazwa bramki w kontrakcie bez kodu to gate_not_implemented, nie passed', function (): void {
    [$result, $document] = Harness::runJson([engineGate('format')], [], ['format', 'mutation']);

    expect($result->exitCode)->toBe(4)
        ->and(Harness::statuses($document))->toBe(['format' => 'passed', 'mutation' => 'not_scanned'])
        ->and(Harness::at($document, 'gates.1.reason.code'))->toBe('gate_not_implemented');
});

it('stany niezgodne z kontraktem są odrzucane przez walidację wyniku (kod 5)', function (GateResult $bad): void {
    [$result, $document] = Harness::runJson([engineGate('format')->does(fn(): GateResult => $bad)]);

    expect($result->exitCode)->toBe(5)
        ->and(Harness::at($document, 'status'))->toBe('error')
        ->and(Harness::schemaErrors($document))->toBe([]);
})->with([
    'skipped bez powodu' => [new GateResult(GateStatus::Skipped)],
    'skipped z powodem, który nie jest decyzją zakresu' => [(new ResultFactory())->skipped(ReasonCode::ToolMissing, 'brak narzędzia')],
    'not_scanned z powodem zakresu' => [(new ResultFactory())->notScanned(ReasonCode::NoMatchingFiles, 'za mało plików')],
    'not_scanned bez powodu' => [new GateResult(GateStatus::NotScanned)],
    'failed bez diagnostyk' => [new GateResult(GateStatus::Failed, null, new Checked(CheckedUnit::Checks, 1))],
    'passed z diagnostykami' => [new GateResult(GateStatus::Passed, null, new Checked(CheckedUnit::Checks, 1), [new Diagnostic('x')])],
    'passed z innym checked.unit niż deklaracja' => [new GateResult(GateStatus::Passed, null, new Checked(CheckedUnit::Files, 1))],
]);

it('blocked_by jest wymagane dokładnie dla precondition_failed', function (): void {
    $bad = engineGate('format')->does(fn(): GateResult => (new ResultFactory())->notScanned(ReasonCode::PreconditionFailed, 'zablokowana'));
    [$result] = Harness::runJson([$bad]);

    expect($result->exitCode)->toBe(5);
});

it('bramka wstępna failed: kolejne bramki nie biegną, mają precondition_failed z blocked_by', function (): void {
    $log = new EventLog();
    [$result, $document] = Harness::runJson([
        engineGate('env', GateMode::Blocking, true)->fails('zła wersja PHP'),
        engineGate('format', log: $log),
        engineGate('phpstan', log: $log),
    ]);

    expect($result->exitCode)->toBe(1)
        ->and($log->lines)->toBe([])
        ->and(Harness::statuses($document))->toBe(['env' => 'failed', 'format' => 'not_scanned', 'phpstan' => 'not_scanned'])
        ->and(Harness::at($document, 'gates.1.reason'))->toBe(['code' => 'precondition_failed', 'message' => 'Bramka wstępna „env” nie przeszła, kolejne bramki nie biegną.', 'blocked_by' => 'env'])
        ->and(Harness::at($document, 'gates.2.reason.blocked_by'))->toBe('env');
});

it('bramka wstępna not_scanned daje kod 4 i zatrzymuje kolejne bramki', function (): void {
    $log = new EventLog();
    [$result, $document] = Harness::runJson([engineGate('env', GateMode::Blocking, true)->missingTool(), engineGate('format', log: $log)]);

    expect($result->exitCode)->toBe(4)
        ->and($log->lines)->toBe([])
        ->and(Harness::at($document, 'gates.1.reason.code'))->toBe('precondition_failed');
});

it('bramka wstępna biegnie pierwsza, nawet gdy w kolejności kanonicznej stoi później', function (): void {
    $log = new EventLog();
    Harness::runJson([engineGate('format', log: $log), engineGate('phpmd', GateMode::Blocking, true, $log)]);

    expect(array_values(array_filter($log->lines, static fn(string $line): bool => str_starts_with($line, 'run:'))))->toBe(['run:phpmd', 'run:format']);
});

it('bramka wstępna skipped nie przerywa biegu', function (): void {
    $log = new EventLog();
    $pre = engineGate('env', GateMode::Blocking, true)->does(fn(): GateResult => (new ResultFactory())->skipped(ReasonCode::DisabledInConfig, 'Wyłączona w konfiguracji z uzasadnieniem.'));
    [$result, $document] = Harness::runJson([$pre, engineGate('format', log: $log)]);

    expect($result->exitCode)->toBe(0)
        ->and($log->lines)->toContain('run:format')
        ->and(Harness::statuses($document))->toBe(['env' => 'skipped', 'format' => 'passed']);
});

it('rejestr odrzuca bramkę raportującą wstępną, duplikat i nieznaną nazwę', function (): void {
    expect(fn() => new GateRegistry(new FakeGate('env', GateMode::Reporting, true)))->toThrow(EngineException::class, 'tylko bramka blokująca')
        ->and(fn() => new GateRegistry(engineGate('format'), engineGate('format')))->toThrow(EngineException::class, 'dwa razy')
        ->and(fn() => new GateRegistry(engineGate('nie-ma-takiej')))->toThrow(EngineException::class, 'znaną nazwą');
});

it('pusty stage: bramki biegną w pełnym zakresie, żadna nie dostaje skipped, kod nie wynika z braku plików', function (): void {
    $seen = [];
    $probe = engineGate('format')->does(static function (GateContext $context) use (&$seen): GateResult {
        $seen = ['scope' => $context->scope->value, 'files' => $context->files];

        return (new ResultFactory())->failed([new Diagnostic('pełny zakres')], new Checked(CheckedUnit::Checks, 1));
    });
    [$result, $document] = Harness::runJson([$probe, engineGate('phpstan')], ['--staged'], null, new FakeGit(staged: []));

    expect($seen)->toBe(['scope' => 'staged', 'files' => null])
        ->and($result->exitCode)->toBe(1)
        ->and(Harness::at($document, 'scope'))->toBe('staged')
        ->and(array_values(Harness::statuses($document)))->not->toContain('skipped');
});

it('niepusty stage trafia do kontekstu bramki jako lista plików', function (): void {
    $seen = null;
    $probe = engineGate('format')->does(static function (GateContext $context) use (&$seen): GateResult {
        $seen = $context->files;

        return (new ResultFactory())->passed(new Checked(CheckedUnit::Checks, 1));
    });
    Harness::runJson([$probe], ['--staged'], null, new FakeGit(staged: ['src/A.php', 'README.md']));

    expect($seen)->toBe(['src/A.php', 'README.md']);
});

it('--base: poprawna rewizja daje diff_base z OID w kontekście bramki i w JSON', function (): void {
    $oid = str_repeat('a1', 20);
    $seen = 'nie wywołano';
    $probe = engineGate('changed-lines')->does(static function (GateContext $context) use (&$seen): GateResult {
        $seen = $context->diffBase;

        return (new ResultFactory())->passed(new Checked(CheckedUnit::Checks, 1));
    });
    [$result, $document] = Harness::runJson([$probe], ['--base=origin/main'], null, new FakeGit(revisions: ['origin/main' => $oid]));

    expect($result->exitCode)->toBe(0)->and($seen)->toBe($oid)->and(Harness::at($document, 'diff_base'))->toBe($oid)
        ->and(Harness::schemaErrors($document))->toBe([]);
});

it('bez --base bramka wymagająca bazy ma not_scanned / missing_input, nie passed', function (): void {
    $needsBase = engineGate('changed-lines')->does(fn(GateContext $context): GateResult => $context->diffBase === null
        ? (new ResultFactory())->notScanned(ReasonCode::MissingInput, 'Brak --base: nie ma punktu odniesienia dla diffu.')
        : (new ResultFactory())->passed(new Checked(CheckedUnit::Checks, 1)));
    [$result, $document] = Harness::runJson([$needsBase]);

    expect($result->exitCode)->toBe(4)
        ->and(Harness::at($document, 'diff_base'))->toBeNull()
        ->and(Harness::at($document, 'gates.0.reason.code'))->toBe('missing_input');
});

it('nierozwiązywalna rewizja --base daje kod 2 z error.code invalid_base i wskazówką', function (): void {
    [$result, $document] = Harness::runJson([engineGate('format')], ['--base=literowka'], null, new FakeGit());

    expect($result->exitCode)->toBe(2)
        ->and(Harness::at($document, 'status'))->toBe('usage_error')
        ->and(Harness::at($document, 'error.code'))->toBe('invalid_base')
        ->and(Harness::at($document, 'error.hint'))->toContain('fetch-depth: 0')
        ->and(Harness::at($document, 'scope'))->toBeNull()
        ->and(Harness::at($document, 'diff_base'))->toBeNull()
        ->and(Harness::at($document, 'gates'))->toBe([])
        ->and(Harness::schemaErrors($document))->toBe([]);
});

it('--base poza repozytorium git też jest invalid_base (kod 2)', function (): void {
    [$result, $document] = Harness::runJson([engineGate('format')], ['--base=main'], null, new FakeGit(repository: false));

    expect($result->exitCode)->toBe(2)->and(Harness::at($document, 'error.code'))->toBe('invalid_base');
});

it('--base razem z --staged wykluczają się (kod 2)', function (): void {
    [$result, $document] = Harness::runJson([engineGate('format')], ['--staged', '--base=main'], null, new FakeGit(revisions: ['main' => str_repeat('b', 40)]));

    expect($result->exitCode)->toBe(2)->and(Harness::at($document, 'error.code'))->toBe('usage_error');
});

it('--staged poza repozytorium git to błąd użycia (kod 2)', function (): void {
    [$result, $document] = Harness::runJson([engineGate('format')], ['--staged'], null, new FakeGit(repository: false));

    expect($result->exitCode)->toBe(2)->and(Harness::at($document, 'status'))->toBe('usage_error');
});

it('błąd użycia: nieznana opcja daje kod 2, dokument JSON gdy --format=json, a tekst na stderr', function (): void {
    [$json, $document] = Harness::runJson([engineGate('format')], ['--nieznana']);
    $text = Harness::run([engineGate('format')], ['--nieznana']);

    expect($json->exitCode)->toBe(2)
        ->and(Harness::at($document, 'status'))->toBe('usage_error')
        ->and(Harness::at($document, 'exit_code'))->toBe(2)
        ->and(Harness::at($document, 'complete'))->toBeFalse()
        ->and($json->stderr)->toContain('Nieznana opcja')
        ->and(Harness::schemaErrors($document))->toBe([])
        ->and($text->exitCode)->toBe(2)
        ->and($text->stdout)->toBe('')
        ->and($text->stderr)->toContain('Nieznana opcja');
});

it('nieznana wartość --format to kod 2 i pusty stdout', function (): void {
    $result = Harness::run([engineGate('format')], ['--format=xml']);

    expect($result->exitCode)->toBe(2)->and($result->stdout)->toBe('')->and($result->stderr)->toContain('--format');
});

it('pc.json: brak pliku, zły JSON, nieznana bramka i brak włączonych bramek dają kod 3 (config_error)', function (?string $contents): void {
    $path = (string) tempnam(sys_get_temp_dir(), 'pcjson');
    if ($contents === null) {
        unlink($path);
    } else {
        file_put_contents($path, $contents);
    }
    $result = (new Application())->run(['run', '--format=json', '--config=' . $path]);
    if (is_file($path)) {
        unlink($path);
    }
    /** @var array<string, mixed> $document */
    $document = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);

    expect($result->exitCode)->toBe(3)
        ->and(Harness::at($document, 'status'))->toBe('config_error')
        ->and(Harness::at($document, 'error.code'))->toBe('config_error')
        ->and(Harness::at($document, 'gates'))->toBe([])
        ->and(Harness::at($document, 'scope'))->toBeNull()
        ->and(Harness::schemaErrors($document))->toBe([]);
})->with([
    'brak pliku' => [null],
    'zły JSON' => ['{ nie json'],
    'nieznana bramka' => ['{"version":1,"gates":{"nieistniejaca":true}}'],
    'gates puste' => ['{"version":1,"gates":{}}'],
    'wszystkie false' => ['{"version":1,"gates":{"format":false}}'],
    'zła wersja schematu' => ['{"version":2,"gates":{"format":true}}'],
]);

it('config:validate zachowuje kody 0 / 1 / 2 (bez kodu 3)', function (): void {
    $application = new Application();

    expect($application->run(['config:validate', __DIR__ . '/../Fixtures/valid-pc.json'])->exitCode)->toBe(0)
        ->and($application->run(['config:validate', __DIR__ . '/../Fixtures/nie-ma.json'])->exitCode)->toBe(1)
        ->and($application->run(['config:nie-ma'])->exitCode)->toBe(2);
});
