<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Tests\Support;

use CityCouriers\Pc\Application;
use CityCouriers\Pc\CommandResult;
use CityCouriers\Pc\Config\ConfigValidator;
use CityCouriers\Pc\Engine\Gate;
use CityCouriers\Pc\Engine\Git;
use CityCouriers\Pc\Engine\GateRegistry;
use CityCouriers\Pc\Engine\InterruptSignal;
use CityCouriers\Pc\Engine\NeverInterrupted;
use CityCouriers\Pc\Engine\RunCommandFactory;

/**
 * Pomoc testowa: uruchamia `pc run` w procesie testu z atrapami i zwraca wynik oraz zdekodowany dokument JSON.
 */
final class Harness
{
    /**
     * Zapisuje tymczasowy `pc.json` z włączonymi bramkami i zwraca jego ścieżkę.
     *
     * @param list<string> $enabled
     */
    public static function config(array $enabled): string
    {
        $gates = [];
        foreach (ConfigValidator::GATES as $name) {
            $gates[$name] = in_array($name, $enabled, true);
        }
        $path = (string) tempnam(sys_get_temp_dir(), 'pcjson');
        file_put_contents($path, json_encode(['version' => 1, 'gates' => $gates], JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * @param list<Gate>   $gates      zarejestrowane bramki
     * @param list<string> $enabled    włączone w konfiguracji (domyślnie: nazwy zarejestrowanych bramek)
     * @param list<string> $arguments  dodatkowe opcje `pc run`
     *
     * @return array{CommandResult, array<string, mixed>}
     */
    public static function runJson(array $gates, array $arguments = [], ?array $enabled = null, ?Git $git = null, ?InterruptSignal $interrupt = null): array
    {
        $result = self::run($gates, ['--format=json', ...$arguments], $enabled, $git, $interrupt);
        /** @var array<string, mixed> $document */
        $document = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);

        return [$result, $document];
    }

    /**
     * @param list<Gate>   $gates
     * @param list<string> $arguments
     * @param list<string>|null $enabled
     */
    public static function run(array $gates, array $arguments = [], ?array $enabled = null, ?Git $git = null, ?InterruptSignal $interrupt = null): CommandResult
    {
        $names = $enabled ?? array_map(static fn(Gate $gate): string => $gate->name(), $gates);
        $config = self::config($names);
        $factory = new RunCommandFactory($interrupt ?? new NeverInterrupted(), $git ?? new FakeGit(), new GateRegistry(...$gates), sys_get_temp_dir());
        $result = (new Application($factory))->run(['run', '--config=' . $config, ...$arguments]);
        unlink($config);

        return $result;
    }

    /**
     * @param array<mixed> $document
     *
     * @return list<string> błędy schematu
     */
    public static function schemaErrors(array $document): array
    {
        return MiniJsonSchema::forRun()->validate($document);
    }

    /**
     * Wartość z dokumentu po ścieżce z kropkami ("gates.0.reason.code"); \`null\`, gdy ścieżki nie ma.
     *
     * @param array<mixed> $document
     */
    public static function at(array $document, string $path): mixed
    {
        $node = $document;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * Kopia dokumentu z podmienioną wartością pod ścieżką z kropkami.
     *
     * @param array<mixed> $document
     *
     * @return array<mixed>
     */
    public static function with(array $document, string $path, mixed $value): array
    {
        return self::edit($document, explode('.', $path), $value, false);
    }

    /**
     * Kopia dokumentu bez klucza pod ścieżką z kropkami.
     *
     * @param array<mixed> $document
     *
     * @return array<mixed>
     */
    public static function without(array $document, string $path): array
    {
        return self::edit($document, explode('.', $path), null, true);
    }

    /**
     * @param array<mixed> $node
     * @param list<string> $segments
     *
     * @return array<mixed>
     */
    private static function edit(array $node, array $segments, mixed $value, bool $remove): array
    {
        $key = (string) array_shift($segments);
        if ($segments === []) {
            if ($remove) {
                unset($node[$key]);
            } else {
                $node[$key] = $value;
            }

            return $node;
        }
        $child = $node[$key] ?? [];
        $node[$key] = self::edit(is_array($child) ? $child : [], $segments, $value, $remove);

        return $node;
    }

    public static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * Statusy wpisów w kolejności raportu: ["format" => "passed", ...].
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, string>
     */
    public static function statuses(array $document): array
    {
        $statuses = [];
        foreach ((array) self::at($document, 'gates') as $index => $gate) {
            $statuses[self::text(self::at($document, 'gates.' . $index . '.name'))] = self::text(self::at($document, 'gates.' . $index . '.status'));
        }

        return $statuses;
    }
}
