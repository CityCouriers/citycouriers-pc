<?php

declare(strict_types=1);

/**
 * Podproces testu SIGINT: uruchamia `pc run` z atrapą bramki, która czeka na sygnał. Marker (argument) niesie
 * kolejno "ready" (bramka zaczęła) i "finalized" (faza finalize się wykonała).
 */

use CityCouriers\Pc\Application;
use CityCouriers\Pc\Engine\Checked;
use CityCouriers\Pc\Engine\CheckedUnit;
use CityCouriers\Pc\Engine\Diagnostic;
use CityCouriers\Pc\Engine\EndCause;
use CityCouriers\Pc\Engine\GateRegistry;
use CityCouriers\Pc\Engine\ProcessInterrupt;
use CityCouriers\Pc\Engine\ResultFactory;
use CityCouriers\Pc\Engine\RunCommandFactory;
use CityCouriers\Pc\Tests\Support\EventLog;
use CityCouriers\Pc\Tests\Support\FakeGate;

require __DIR__ . '/../../vendor/autoload.php';

$marker = $argv[1];
$interrupt = new ProcessInterrupt();
$log = new EventLog();

$gate = (new FakeGate('format', log: $log))
    ->does(static function () use ($marker, $interrupt): \CityCouriers\Pc\Engine\GateResult {
        file_put_contents($marker, 'ready');
        $deadline = microtime(true) + 10;
        while ($interrupt->signal() === null && microtime(true) < $deadline) {
            usleep(10_000);
        }

        return (new ResultFactory())->failed([new Diagnostic('pierwotne')], new Checked(CheckedUnit::Checks, 1));
    })
    ->withFinalize(static function ($context, $result, EndCause $cause) use ($marker): \CityCouriers\Pc\Engine\GateResult {
        file_put_contents($marker, 'finalized');

        return (new ResultFactory())->failed([new Diagnostic('finalize: ' . $cause->value)], new Checked(CheckedUnit::Checks, 1));
    });

$config = (string) tempnam(sys_get_temp_dir(), 'pcjson');
file_put_contents($config, '{"version":1,"gates":{"format":true}}');

$result = (new Application(new RunCommandFactory($interrupt, null, new GateRegistry($gate))))->run(['run', '--format=json', '--config=' . $config]);
unlink($config);

fwrite(STDOUT, $result->stdout);
exit($result->exitCode);
