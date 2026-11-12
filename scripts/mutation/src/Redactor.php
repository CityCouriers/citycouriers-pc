<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Dev\Mutation;

/**
 * Usuwa ścieżki z maszyny (katalog repo, katalog interpretera PHP, katalog domowy) z logów i manifestu, które trafiają
 * do opisu PR w publicznym repo.
 */
final readonly class Redactor
{
    /**
     * @var array<string, string> ścieżka → zamiennik, od najdłuższej
     */
    private array $replacements;

    public function __construct(string $root, string $phpBinary, ?string $home)
    {
        $replacements = [$root => '.', dirname($phpBinary) => '<php>'];
        if ($home !== null && $home !== '') {
            $replacements[$home] = '~';
        }
        $variants = [];
        foreach ($replacements as $path => $replacement) {
            $trimmed = rtrim($path, '/\\');
            if ($trimmed === '') {
                continue;
            }
            foreach ([$trimmed, str_replace('\\', '/', $trimmed), str_replace('/', '\\', $trimmed), str_replace(['\\', '/'], '\\\\', $trimmed)] as $variant) {
                $variants[$variant] = $replacement;
            }
        }
        uksort($variants, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));
        $this->replacements = $variants;
    }

    public function redact(string $text): string
    {
        foreach ($this->replacements as $path => $replacement) {
            $text = str_ireplace($path, $replacement, $text);
        }

        return $text;
    }

    public function redactFile(string $path): void
    {
        if (is_file($path)) {
            file_put_contents($path, $this->redact((string) file_get_contents($path)));
        }
    }
}
