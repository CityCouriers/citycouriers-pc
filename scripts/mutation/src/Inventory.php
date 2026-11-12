<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Dev\Mutation;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Lista celów mutacji: wszystkie pliki PHP w katalogu (np. `src` albo jego podkatalog dla biegu w kawałku).
 */
final class Inventory
{
    /**
     * @return list<Target> posortowane po ścieżce
     */
    public static function scan(string $root, string $directory): array
    {
        $base = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $directory;
        if (is_file($base)) {
            return [self::target($root, $base)];
        }
        if (!is_dir($base)) {
            throw new RuntimeException('Nie ma katalogu celów mutacji: ' . $directory);
        }
        $targets = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $targets[] = self::target($root, $file->getPathname());
            }
        }
        usort($targets, static fn(Target $left, Target $right): int => strcmp($left->path, $right->path));

        return $targets;
    }

    /**
     * SHA-256 treści po zamianie CRLF i CR na LF (ten sam skrót na Windows i Linuksie, niezależnie od autocrlf).
     */
    public static function hash(string $content): string
    {
        return hash('sha256', str_replace(["\r\n", "\r"], "\n", $content));
    }

    /**
     * Pełne nazwy klas, interfejsów, cech i wyliczeń zadeklarowanych w kodzie.
     *
     * @return list<string>
     */
    public static function classes(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn(array|string $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $namespace = '';
        $classes = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }
            $next = $tokens[$index + 1] ?? null;
            $previous = $tokens[$index - 1] ?? null;
            if ($token[0] === T_NAMESPACE && is_array($next) && in_array($next[0], [T_NAME_QUALIFIED, T_STRING], true)) {
                $namespace = $next[1] . '\\';
            }
            $declares = in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true);
            $isClassConstant = is_array($previous) && $previous[0] === T_DOUBLE_COLON;
            if ($declares && !$isClassConstant && is_array($next) && $next[0] === T_STRING) {
                $classes[] = $namespace . $next[1];
            }
        }

        return $classes;
    }

    private static function target(string $root, string $path): Target
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Nie można odczytać celu mutacji: ' . $path);
        }

        return new Target(Journal::relative($path, $root), self::hash($content), self::classes($content));
    }
}
