<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Dev\Mutation;

/**
 * Jawna lista celów, dla których wtyczka nie generuje mutantów, z uzasadnieniem (scripts/mutation/bez-mutantow.json).
 * Cel bez mutantów i bez wpisu odrzuca bieg; tak samo wpis dla celu, który ma mutanty albo nie istnieje.
 */
final readonly class ZeroMutants
{
    /**
     * @param array<string, string> $files ścieżka → uzasadnienie
     * @param list<string> $errors
     */
    public function __construct(
        public array $files,
        public array $errors,
    ) {}

    public static function parse(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['pliki'] ?? null)) {
            return new self([], ['bez-mutantow.json: oczekiwany obiekt z "version": 1 i obiektem "pliki"']);
        }
        $files = [];
        $errors = [];
        foreach ($data['pliki'] as $path => $reason) {
            if (!is_string($path) || !is_string($reason) || trim($reason) === '') {
                $errors[] = sprintf('bez-mutantow.json: wpis "%s" bez uzasadnienia', $path);
                continue;
            }
            $files[$path] = $reason;
        }

        return new self($files, $errors);
    }
}
