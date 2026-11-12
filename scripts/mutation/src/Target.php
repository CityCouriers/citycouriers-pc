<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Dev\Mutation;

/**
 * Cel mutacji: plik PHP z `src` (ścieżka względna z `/`), SHA-256 jego treści po normalizacji końców linii
 * i zadeklarowane w nim klasy, interfejsy, cechy i wyliczenia (pełne nazwy).
 */
final readonly class Target
{
    /**
     * @param list<string> $classes
     */
    public function __construct(
        public string $path,
        public string $sha256,
        public array $classes,
    ) {}
}
