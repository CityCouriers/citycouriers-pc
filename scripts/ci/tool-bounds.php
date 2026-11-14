<?php

declare(strict_types=1);

/**
 * Dolne granice narzędzi deweloperskich (zgłoszenie #71, docs/proces.md, "Aktualizacja narzędzi").
 *
 * Dla każdego pakietu z `require-dev` porównuje dolną granicę ograniczenia z `composer.json` oraz zainstalowaną wersję
 * z najnowszą stabilną. Kod wyjścia 1, gdy któraś jest różna od najnowszej stabilnej (niższa, wyższa albo niestabilna), a pakiet nie ma wpisu w pliku wyjątków (z powodem).
 * Wersje deweloperskie (`3.x-dev`, `dev-main`) nie mają dolnej granicy, więc też wymagają wpisu.
 *
 * Użycie: php scripts/ci/tool-bounds.php [--manifest=composer.json] [--installed=vendor/composer/installed.json]
 *             [--latest=plik.json] [--exceptions=scripts/ci/tool-bounds-exceptions.json]
 * Bez `--latest` najnowsza stabilna pochodzi z Packagist (repo.packagist.org); `--latest` to mapa pakiet => wersja (testy).
 */

/**
 * Najniższa dolna granica ograniczenia (trzy człony). Obsługujemy tylko `^X.Y.Z`, `~X.Y.Z`, `>=X.Y.Z` i `X.Y.Z`
 * (też alternatywy `||`); wszystko inne (wersje deweloperskie i wstępne, górne granice, ograniczenia wieloczłonowe, `*`)
 * to null, czyli brak pewnej dolnej granicy, i wymaga wpisu wyjątku.
 */
function lowerBound(string $constraint): ?string
{
    $bounds = [];
    foreach (preg_split('/\s*\|\|?\s*/', trim($constraint)) ?: [] as $alternative) {
        if (preg_match('/^(?:\^|~|>=)?v?(\d+(?:\.\d+){0,2})$/', $alternative, $found) !== 1) {
            return null;
        }
        $bounds[] = padVersion($found[1]);
    }
    usort($bounds, 'version_compare');

    return $bounds[0] ?? null;
}

function padVersion(string $version): string
{
    return implode('.', array_pad(explode('.', $version), 3, '0'));
}

/** Najwyższa wersja stabilna (bez przyrostków dev, alpha, beta, RC) z odpowiedzi Packagist p2. */
function latestStable(string $json): ?string
{
    $best = null;
    foreach ((array) (json_decode($json, true, 512, JSON_THROW_ON_ERROR)['packages'] ?? []) as $versions) {
        foreach ((array) $versions as $entry) {
            $version = ltrim((string) ($entry['version'] ?? ''), 'v');
            if (preg_match('/^\d+(\.\d+){0,2}$/', $version) === 1 && ($best === null || version_compare($version, $best, '>'))) {
                $best = $version;
            }
        }
    }

    return $best;
}

/**
 * @param array<string, string> $require    require-dev z manifestu
 * @param array<string, string> $installed  pakiet => zainstalowana wersja
 * @param array<string, string> $latest     pakiet => najnowsza stabilna
 * @param array<string, string> $exceptions pakiet => powód
 *
 * @return list<string> naruszenia
 */
function boundViolations(array $require, array $installed, array $latest, array $exceptions): array
{
    $found = [];
    foreach ($require as $package => $constraint) {
        if (isset($exceptions[$package]) && trim($exceptions[$package]) !== '') {
            continue;
        }
        $stable = isset($latest[$package]) ? padVersion($latest[$package]) : null;
        $bound = lowerBound($constraint);
        $have = ltrim($installed[$package] ?? '', 'v');
        if ($stable === null) {
            $found[] = $package . ': brak najnowszej stabilnej wersji (dodaj wpis wyjątku z powodem)';
        } elseif ($bound === null) {
            $found[] = $package . ': ograniczenie "' . $constraint . '" bez pewnej dolnej granicy (wersja deweloperska, wstępna albo nieobsługiwana składnia wymaga wpisu wyjątku)';
        } elseif (version_compare($bound, $stable) !== 0) {
            $found[] = $package . ': dolna granica ' . $bound . ' różna od najnowszej stabilnej ' . $stable;
        } elseif (preg_match('/^\d+(\.\d+){0,2}$/', $have) !== 1 || version_compare(padVersion($have), $stable) !== 0) {
            $found[] = $package . ': zainstalowana ' . ($have === '' ? '(brak)' : $have) . ' różna od najnowszej stabilnej ' . $stable . ' (albo niestabilna)';
        }
    }

    return $found;
}

/** @return array<string, mixed> */
function readJson(string $path): array
{
    $content = file_get_contents($path);
    if ($content === false) {
        fwrite(STDERR, "Nie można odczytać: $path\n");
        exit(2);
    }

    return (array) json_decode($content, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @param array<string, string> $require
 *
 * @return array<string, string>
 */
function fetchLatest(array $require, ?string $file): array
{
    if ($file !== null) {
        return array_map('strval', readJson($file));
    }
    $latest = [];
    foreach (array_keys($require) as $package) {
        $json = @file_get_contents('https://repo.packagist.org/p2/' . $package . '.json');
        $stable = $json === false ? null : latestStable($json);
        if ($stable !== null) {
            $latest[$package] = $stable;
        }
    }

    return $latest;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $root = dirname(__DIR__, 2);
    $options = ['manifest' => $root . '/composer.json', 'installed' => $root . '/vendor/composer/installed.json',
        'latest' => null, 'exceptions' => __DIR__ . '/tool-bounds-exceptions.json'];
    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--(manifest|installed|latest|exceptions)=(.+)$/', $argument, $parts) !== 1) {
            fwrite(STDERR, "Nieznany argument: $argument\n");
            exit(2);
        }
        $options[$parts[1]] = $parts[2];
    }
    $require = array_map('strval', (array) (readJson((string) $options['manifest'])['require-dev'] ?? []));
    $installed = [];
    foreach ((array) (readJson((string) $options['installed'])['packages'] ?? []) as $package) {
        $installed[(string) $package['name']] = (string) $package['version'];
    }
    $violations = boundViolations($require, $installed, fetchLatest($require, $options['latest']), array_map('strval', readJson((string) $options['exceptions'])));
    foreach ($violations as $violation) {
        fwrite(STDERR, $violation . "\n");
    }
    if ($violations === []) {
        echo 'Dolne granice: OK (' . count($require) . " pakietów)\n";
    }
    exit($violations === [] ? 0 : 1);
}
