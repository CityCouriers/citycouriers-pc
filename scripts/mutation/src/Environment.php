<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Dev\Mutation;

use Pest\Mutate\MutationTest;
use ReflectionClass;

/**
 * Stan repo i środowiska do manifestu: SHA bazowy, zmiany w drzewie roboczym, wersje narzędzi, skróty konfiguracji
 * i warunki ważności biegu (aktywna łatka wtyczki bez dryfu, sterownik pokrycia).
 */
final class Environment
{
    private const array PACKAGES = ['pestphp/pest', 'pestphp/pest-plugin-mutate', 'phpunit/phpunit', 'phpunit/php-code-coverage', 'symfony/process'];

    private const array CONFIGS = ['composer.json', 'composer.lock', 'phpunit.xml.dist', 'scripts/mutation/patch/MutationTest.php', 'scripts/mutation/bez-mutantow.json'];

    private const string PATCH = 'scripts/mutation/patch/MutationTest.php';

    private const string UPSTREAM = 'vendor/pestphp/pest-plugin-mutate/src/MutationTest.php';

    /**
     * @return array<string, mixed>
     */
    public static function collect(string $root): array
    {
        [, $sha] = PestRun::capture(['git', 'rev-parse', 'HEAD'], $root);
        [, $status] = PestRun::capture(['git', 'status', '--porcelain', '--untracked-files=all'], $root);
        $hashes = [];
        foreach (self::CONFIGS as $file) {
            $path = $root . '/' . $file;
            $hashes[$file] = is_file($path) ? Inventory::hash((string) file_get_contents($path)) : null;
        }

        return [
            'sha' => trim($sha),
            'zmiany_w_drzewie' => array_values(array_filter(array_map('rtrim', explode("\n", $status)), static fn(string $line): bool => $line !== '')),
            'php' => PHP_VERSION . (PHP_ZTS === 1 ? ' ZTS' : ' NTS'),
            'system' => PHP_OS_FAMILY,
            'sterownik_pokrycia' => self::coverageDriver(),
            'wersje' => self::versions($root),
            'skroty' => $hashes,
        ];
    }

    /**
     * Warunki ważności biegu niezależne od wyniku mutantów.
     *
     * @param array<string, mixed> $environment wynik collect()
     *
     * @return list<string>
     */
    public static function problems(string $root, array $environment): array
    {
        $problems = [];
        if (($environment['sha'] ?? '') === '') {
            $problems[] = 'nie udało się ustalić SHA bazowego (git rev-parse HEAD)';
        }
        if (($environment['zmiany_w_drzewie'] ?? []) !== []) {
            $problems[] = 'drzewo robocze ma niezatwierdzone zmiany: wynik nie jest dowodem dla SHA bazowego';
        }
        $loaded = (string) (new ReflectionClass(MutationTest::class))->getFileName();
        if (Journal::relative($loaded, $root) !== self::PATCH) {
            $problems[] = 'łatka wtyczki nieaktywna: Pest\Mutate\MutationTest ładuje się z ' . Journal::relative($loaded, $root) . ' (composer dump-autoload)';
        }
        $upstream = $root . '/' . self::UPSTREAM;
        if (!is_file($upstream) || hash_file('sha256', $upstream) !== MutationTest::UPSTREAM_SHA256) {
            $problems[] = 'dryf wtyczki: oryginał ' . self::UPSTREAM . ' różni się od wersji, z której zrobiono łatkę (przenieś zmiany i zaktualizuj UPSTREAM_SHA256)';
        }

        return $problems;
    }

    /**
     * Ustawienia PHP (-d) dla biegu z pokryciem albo null, gdy nie ma sterownika pokrycia.
     *
     * @return list<string>|null
     */
    public static function coverageIni(string $directory): ?array
    {
        if (extension_loaded('pcov')) {
            return ['-d', 'pcov.enabled=1', '-d', 'pcov.directory=' . $directory, '-d', 'xdebug.mode=off'];
        }

        return extension_loaded('xdebug') ? ['-d', 'xdebug.mode=coverage'] : null;
    }

    private static function coverageDriver(): string
    {
        if (extension_loaded('pcov')) {
            return 'pcov ' . phpversion('pcov');
        }

        return extension_loaded('xdebug') ? 'xdebug ' . phpversion('xdebug') : 'brak';
    }

    /**
     * @return array<string, string>
     */
    private static function versions(string $root): array
    {
        $installed = json_decode((string) @file_get_contents($root . '/vendor/composer/installed.json'), true);
        $versions = array_fill_keys(self::PACKAGES, 'nieznana');
        $packages = is_array($installed) && is_array($installed['packages'] ?? null) ? $installed['packages'] : [];
        foreach ($packages as $package) {
            if (is_array($package) && is_string($package['name'] ?? null) && isset($versions[$package['name']]) && is_string($package['version'] ?? null)) {
                $versions[$package['name']] = $package['version'];
            }
        }

        return $versions;
    }
}
