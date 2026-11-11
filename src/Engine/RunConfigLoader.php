<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

use CityCouriers\Pc\Config\ConfigException;
use CityCouriers\Pc\Config\ConfigLoader;
use CityCouriers\Pc\Config\ConfigValidator;

/**
 * Wczytuje konfigurację `pc run`: wczytana, zwalidowana, z listą włączonych bramek. Brak jakiejkolwiek włączonej bramki
 * to błąd konfiguracji (fail-closed), a nie zielone 0.
 */
final class RunConfigLoader
{
    /**
     * @throws ConfigException
     */
    public function load(string $path): RunConfig
    {
        $config = (new ConfigLoader())->load($path);
        $errors = (new ConfigValidator())->validate($config);
        if ($errors !== []) {
            throw new ConfigException(implode("\n", $errors));
        }

        $gates = is_array($config['gates'] ?? null) ? $config['gates'] : [];
        $enabled = array_map('strval', array_keys(array_filter($gates, static fn(mixed $value): bool => $value === true)));
        if ($enabled === []) {
            throw new ConfigException('Żadna bramka nie jest włączona w pc.json ("gates" puste albo wszystkie false): nic nie zostałoby sprawdzone.');
        }

        return new RunConfig($config, $enabled);
    }
}
