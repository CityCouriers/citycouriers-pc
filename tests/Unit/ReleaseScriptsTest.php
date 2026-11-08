<?php

declare(strict_types=1);

/**
 * Tworzy tymczasowy korzeń repo z wersją i changelogiem.
 */
function releaseRoot(string $version, string $changelog): string
{
    $root = sys_get_temp_dir() . '/pc-release-' . bin2hex(random_bytes(6));
    mkdir($root . '/src', 0o777, true);
    file_put_contents($root . '/src/Version.php', "<?php\n\nfinal class Version\n{\n    public const string CURRENT = '" . $version . "';\n}\n");
    file_put_contents($root . '/CHANGELOG.md', $changelog);

    return $root;
}

function removeRoot(string $root): void
{
    foreach (['/src/Version.php', '/CHANGELOG.md'] as $file) {
        unlink($root . $file);
    }
    rmdir($root . '/src');
    rmdir($root);
}

/**
 * @return array{int, string}
 */
function runRelease(string $script, string $root, string $argument): array
{
    $command = ['bash', __DIR__ . '/../../scripts/ci/' . $script, $argument];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PC_REPO_ROOT' => $root] + getenv());
    if ($process === false) {
        throw new RuntimeException('Nie udało się uruchomić skryptu wydania.');
    }

    $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $output];
}

const CHANGELOG_OK = "# Changelog\n\n## [Unreleased]\n\n## [1.2.3] - 2026-01-01\n\n### Added\n\n- Coś nowego.\n\n## [1.2.2] - 2025-12-01\n\n- Starsze.\n";

it('akceptuje tag zgodny z wersja i changelogiem', function (): void {
    $root = releaseRoot('1.2.3', CHANGELOG_OK);
    [$exit, $output] = runRelease('check-release.sh', $root, 'v1.2.3');
    removeRoot($root);

    expect($exit)->toBe(0)->and($output)->toContain('OK: tag v1.2.3');
});

it('odrzuca tag niezgodny z Version::CURRENT (kontrola ujemna)', function (): void {
    $root = releaseRoot('1.2.4', CHANGELOG_OK);
    [$exit, $output] = runRelease('check-release.sh', $root, 'v1.2.3');
    removeRoot($root);

    expect($exit)->toBe(1)->and($output)->toContain("Version::CURRENT to '1.2.4'");
});

it('odrzuca tag bez wpisu w changelogu (kontrola ujemna)', function (): void {
    $root = releaseRoot('1.3.0', CHANGELOG_OK);
    [$exit, $output] = runRelease('check-release.sh', $root, 'v1.3.0');
    removeRoot($root);

    expect($exit)->toBe(1)->and($output)->toContain('Brak niepustej sekcji [1.3.0]');
});

it('odrzuca tag, ktory nie jest SemVer (kontrola ujemna)', function (string $tag): void {
    $root = releaseRoot('1.2.3', CHANGELOG_OK);
    [$exit, $output] = runRelease('check-release.sh', $root, $tag);
    removeRoot($root);

    expect($exit)->toBe(1)->and($output)->toContain('nie jest wersją SemVer');
})->with(['1.2.3', 'v1.2', 'v01.2.3', 'wydanie']);

it('wypisuje tylko sekcje wskazanej wersji', function (): void {
    $root = releaseRoot('1.2.3', CHANGELOG_OK);
    [$exit, $output] = runRelease('changelog-section.sh', $root, '1.2.3');
    removeRoot($root);

    expect($exit)->toBe(0)
        ->and($output)->toContain('Coś nowego.')
        ->and($output)->not->toContain('Starsze.');
});
