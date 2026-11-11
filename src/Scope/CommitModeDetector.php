<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Ustala tryb commita z argumentów procesu nadrzędnego hooka (`git [opcje globalne] commit [opcje]`).
 *
 * Dodatnia weryfikacja: `Plain` tylko wtedy, gdy KAŻDY argument należy do zamkniętej allowlisty. Skrócona opcja
 * (`--amen`), alias (`git ci`), opcja spoza listy, pathspec, brak wiersza poleceń i obcy proces dają `Unknown`.
 */
final class CommitModeDetector
{
    private const array GLOBAL_WITH_VALUE = ['-c', '-C'];

    private const array GLOBAL_FLAGS = ['--no-pager', '--no-optional-locks', '--no-replace-objects'];

    private const array LONG_FLAGS = [
        '--all', '--quiet', '--verbose', '--signoff', '--no-verify', '--verify', '--edit', '--no-edit', '--allow-empty',
        '--allow-empty-message', '--status', '--no-status', '--no-gpg-sign', '--no-signoff', '--no-verbose',
    ];

    private const array LONG_WITH_VALUE = ['--message', '--file', '--author', '--date', '--cleanup', '--template', '--trailer'];

    private const string SHORT_FLAGS = 'aqvsne';

    private const string SHORT_WITH_VALUE = 'mFt';

    /**
     * @param list<string>|null $argv argumenty procesu nadrzędnego albo `null`, gdy ich nie da się odczytać
     */
    public function detect(?array $argv): CommitMode
    {
        if ($argv === null || $argv === [] || !$this->isGit($argv[0])) {
            return CommitMode::Unknown;
        }
        $index = $this->commitIndex($argv);
        if ($index === null) {
            return CommitMode::Unknown;
        }

        $amend = $this->options(array_slice($argv, $index + 1));

        return match ($amend) {
            null => CommitMode::Unknown,
            true => CommitMode::Amend,
            false => CommitMode::Plain,
        };
    }

    private function isGit(string $executable): bool
    {
        $parts = preg_split('~[\\\\/]~', $executable);
        $name = strtolower($parts === false ? '' : (string) end($parts));

        return $name === 'git' || $name === 'git.exe';
    }

    /**
     * Indeks tokenu `commit` po opcjach globalnych z allowlisty albo `null`.
     *
     * @param list<string> $argv
     */
    private function commitIndex(array $argv): ?int
    {
        $index = 1;
        while (isset($argv[$index])) {
            $token = $argv[$index];
            $before = $index;
            if ($token === 'commit') {
                return $index;
            }
            $index += $this->globalWidth($token, isset($argv[$index + 1]));
            if ($index === $before) {
                return null;
            }
        }

        return null;
    }

    /**
     * Ile argumentów zajmuje opcja globalna z allowlisty (0 = nieznana).
     */
    private function globalWidth(string $token, bool $hasNext): int
    {
        if (in_array($token, self::GLOBAL_WITH_VALUE, true) && $hasNext) {
            return 2;
        }

        return in_array($token, self::GLOBAL_FLAGS, true) ? 1 : 0;
    }

    /**
     * @param list<string> $arguments argumenty po `commit`
     *
     * @return bool|null `true` gdy jest `--amend`, `false` gdy zwykły commit, `null` gdy cokolwiek spoza allowlisty
     */
    private function options(array $arguments): ?bool
    {
        $amend = false;
        $count = count($arguments);
        for ($index = 0; $index < $count; ++$index) {
            $token = $arguments[$index];
            if ($token === '--amend') {
                $amend = true;
            } elseif (in_array($token, self::LONG_FLAGS, true)) {
                continue;
            } elseif ($this->consumesValue($token, $arguments, $index)) {
                ++$index;
            } elseif (!$this->isAllowedInline($token)) {
                return null;
            }
        }

        return $amend;
    }

    /**
     * Opcja z wartością w następnym argumencie (`-m msg`, `--message msg`, `-am msg`); brak wartości to błąd (`false`).
     *
     * @param list<string> $arguments
     */
    private function consumesValue(string $token, array $arguments, int $index): bool
    {
        if (!isset($arguments[$index + 1])) {
            return false;
        }
        if (in_array($token, self::LONG_WITH_VALUE, true)) {
            return true;
        }
        if (preg_match('~^-([' . self::SHORT_FLAGS . ']*)([' . self::SHORT_WITH_VALUE . '])$~', $token) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Opcje z wartością w tym samym argumencie (`--message=x`, `-mx`, `-S`, `--gpg-sign=id`) i same flagi krótkie (`-qv`).
     */
    private function isAllowedInline(string $token): bool
    {
        $long = explode('=', $token, 2);
        if (count($long) === 2 && (in_array($long[0], self::LONG_WITH_VALUE, true) || $long[0] === '--gpg-sign')) {
            return true;
        }
        if ($token === '--gpg-sign') {
            return true;
        }

        return preg_match('~^-[' . self::SHORT_FLAGS . ']*(?:[' . self::SHORT_WITH_VALUE . '].+|S.*)?$~', $token) === 1 && $token !== '-';
    }
}
