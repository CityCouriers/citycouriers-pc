<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Config;

use JsonException;

/**
 * Wczytuje plik konfiguracji repo-konsumenta (domyślnie `pc.json`).
 */
final class ConfigLoader
{
    /**
     * @return array<mixed>
     *
     * @throws ConfigException
     */
    public function load(string $path): array
    {
        if (!is_file($path)) {
            throw new ConfigException(sprintf('Brak pliku konfiguracji: %s', $path));
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new ConfigException(sprintf('Nie da się odczytać pliku: %s', $path));
        }

        return $this->decode($contents, $path);
    }

    /**
     * @return array<mixed>
     *
     * @throws ConfigException
     */
    private function decode(string $contents, string $path): array
    {
        try {
            $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ConfigException(sprintf('Niepoprawny JSON w %s: %s', $path, $exception->getMessage()), 0, $exception);
        }

        if (!is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
            throw new ConfigException(sprintf('Plik %s musi zawierać obiekt JSON.', $path));
        }

        return $decoded;
    }
}
