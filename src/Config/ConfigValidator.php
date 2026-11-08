<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Config;

/**
 * Kontrakt konfiguracji repo-konsumenta (`pc.json`, wersja schematu 1).
 *
 * Plik wybiera bramki, ścieżki, standard kodowania i zestawy reguł. Walidator niczego nie uruchamia:
 * zwraca listę błędów, a pusta lista oznacza konfigurację poprawną. Ścieżki muszą być względne
 * i bez `..`, żeby konfiguracja nie niosła ścieżek z maszyny autora.
 */
final class ConfigValidator
{
    public const int SCHEMA_VERSION = 1;

    /** Bramki, które `pc` zna. Nowa bramka to zmiana kontraktu i wydanie zgodnie z docs/wydania.md. */
    public const array GATES = [
        'env', 'stash', 'forbidden', 'composer', 'format', 'phpstan', 'phpmd', 'phpcpd',
        'phpcompat', 'tests', 'mutation', 'coverage', 'changed-lines', 'timeguard', 'secrets',
    ];

    public const array STANDARDS = ['pint', 'phpcs'];

    public const array MODULES = ['laravel', 'wordpress'];

    private const array TOP_LEVEL_KEYS = ['version', 'standard', 'paths', 'gates', 'rulesets', 'modules'];

    private const array PATH_KEYS = ['src', 'tests'];

    private const array RULESET_KEYS = ['phpmd', 'phpstan', 'phpcs'];

    /**
     * @param array<mixed> $config
     *
     * @return list<string>
     */
    public function validate(array $config): array
    {
        return [
            ...$this->checkUnknownKeys($config),
            ...$this->checkVersion($config),
            ...$this->checkStandard($config),
            ...$this->checkPaths($config),
            ...$this->checkGates($config),
            ...$this->checkRulesets($config),
            ...$this->checkModules($config),
        ];
    }

    /**
     * @param array<mixed> $config
     *
     * @return list<string>
     */
    private function checkUnknownKeys(array $config): array
    {
        $errors = [];
        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::TOP_LEVEL_KEYS, true)) {
                $errors[] = sprintf('Nieznany klucz: %s', $key);
            }
        }

        return $errors;
    }

    /**
     * @param array<mixed> $config
     *
     * @return list<string>
     */
    private function checkVersion(array $config): array
    {
        if (($config['version'] ?? null) === self::SCHEMA_VERSION) {
            return [];
        }

        return [sprintf('Pole "version" jest wymagane i musi mieć wartość %d.', self::SCHEMA_VERSION)];
    }

    /**
     * @param array<mixed> $config
     *
     * @return list<string>
     */
    private function checkStandard(array $config): array
    {
        if (!array_key_exists('standard', $config)) {
            return [];
        }

        if (is_string($config['standard']) && in_array($config['standard'], self::STANDARDS, true)) {
            return [];
        }

        return [sprintf('Pole "standard" musi być jednym z: %s.', implode(', ', self::STANDARDS))];
    }

    /**
     * @param array<mixed> $config
     *
     * @return list<string>
     */
    private function checkPaths(array $config): array
    {
        $section = $this->section($config, 'paths');
        if (is_string($section)) {
            return [$section];
        }

        $errors = [];
        foreach ($section as $name => $paths) {
            $errors = [...$errors, ...$this->checkPathList((string) $name, $paths)];
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function checkPathList(string $name, mixed $paths): array
    {
        if (!in_array($name, self::PATH_KEYS, true)) {
            return [sprintf('Nieznany klucz w "paths": %s', $name)];
        }

        if (!is_array($paths) || $paths === [] || !array_is_list($paths)) {
            return [sprintf('"paths.%s" musi być niepustą listą ścieżek.', $name)];
        }

        $errors = [];
        foreach ($paths as $path) {
            if (!is_string($path) || !$this->isPortablePath($path)) {
                $errors[] = sprintf('"paths.%s": ścieżka musi być względna, bez "..".', $name);
            }
        }

        return $errors;
    }

    /**
     * @param array<mixed> $config
     *
     * @return list<string>
     */
    private function checkGates(array $config): array
    {
        $section = $this->section($config, 'gates');
        if (is_string($section)) {
            return [$section];
        }

        $errors = [];
        foreach ($section as $name => $enabled) {
            if (!in_array($name, self::GATES, true)) {
                $errors[] = sprintf('Nieznana bramka: %s', $name);
            } elseif (!is_bool($enabled)) {
                $errors[] = sprintf('"gates.%s" musi być wartością true albo false.', $name);
            }
        }

        return $errors;
    }

    /**
     * @param array<mixed> $config
     *
     * @return list<string>
     */
    private function checkRulesets(array $config): array
    {
        $section = $this->section($config, 'rulesets');
        if (is_string($section)) {
            return [$section];
        }

        $errors = [];
        foreach ($section as $name => $path) {
            if (!in_array($name, self::RULESET_KEYS, true)) {
                $errors[] = sprintf('Nieznany zestaw reguł: %s', $name);
            } elseif (!is_string($path) || !$this->isPortablePath($path)) {
                $errors[] = sprintf('"rulesets.%s": ścieżka musi być względna, bez "..".', $name);
            }
        }

        return $errors;
    }

    /**
     * @param array<mixed> $config
     *
     * @return list<string>
     */
    private function checkModules(array $config): array
    {
        $section = $this->section($config, 'modules');
        if (is_string($section)) {
            return [$section];
        }

        $errors = [];
        foreach ($section as $name => $enabled) {
            if (!in_array($name, self::MODULES, true)) {
                $errors[] = sprintf('Nieznany moduł: %s', $name);
            } elseif (!is_bool($enabled)) {
                $errors[] = sprintf('"modules.%s" musi być wartością true albo false.', $name);
            }
        }

        return $errors;
    }

    /**
     * Opcjonalna sekcja-obiekt. Brak sekcji daje pustą tablicę, a zły typ komunikat błędu (string).
     *
     * @param array<mixed> $config
     *
     * @return array<array-key, mixed>|string
     */
    private function section(array $config, string $key): array|string
    {
        if (!array_key_exists($key, $config)) {
            return [];
        }

        $value = $config[$key];
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return sprintf('Pole "%s" musi być obiektem.', $key);
        }

        return $value;
    }

    private function isPortablePath(string $path): bool
    {
        if ($path === '' || str_contains($path, '..') || str_contains($path, '\\')) {
            return false;
        }

        return preg_match('~^(/|[A-Za-z]:)~', $path) !== 1;
    }
}
