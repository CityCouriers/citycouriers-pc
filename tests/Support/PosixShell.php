<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Tests\Support;

/**
 * Powłoka POSIX dla testów skryptów `.sh` i hooków. Na Windows `bash` z PATH bywa wrapperem WSL z WindowsApps
 * (tak jest w PowerShell i cmd, w Git Bash nie), który uruchamia Linuksowe programy i nie widzi ścieżek Windows.
 * Dlatego test jawnie wybiera bash dołączony do Git for Windows, tak jak robi to Git Bash.
 */
final class PosixShell
{
    /**
     * Katalog `usr/bin` z Gita (z `bash.exe`) albo `null` poza Windows lub gdy go nie ma.
     */
    public static function gitDirectory(): ?string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return null;
        }
        $execPath = trim(Child::capture(['git', '--exec-path'])[1]);
        $root = $execPath === '' ? '' : dirname($execPath, 3);
        $directory = str_replace('/', DIRECTORY_SEPARATOR, $root . '/usr/bin');

        return $root !== '' && is_file($directory . DIRECTORY_SEPARATOR . 'bash.exe') ? $directory : null;
    }

    /**
     * Polecenie `bash`: pełna ścieżka do bash z Gita na Windows, inaczej samo `bash` z PATH.
     */
    public static function bash(): string
    {
        $directory = self::gitDirectory();

        return $directory === null ? 'bash' : $directory . DIRECTORY_SEPARATOR . 'bash.exe';
    }

    /**
     * Środowisko procesu potomnego z katalogiem narzędzi Gita (`dirname`, `grep`, `sed`) na początku PATH
     * (bash uruchomiony bezpośrednio, nie jako powłoka logowania, sam ich nie dodaje). Nazwa `Path` z PowerShella
     * i `PATH` z Git Bash są jedną zmienną, więc zostaje jeden klucz.
     *
     * @param array<int|string, string> $extra
     *
     * @return array<string, string>
     */
    public static function environment(array $extra = []): array
    {
        GitRepo::rejectUnpassableNames($extra);
        $environment = [];
        $path = '';
        foreach (array_filter($extra, is_string(...), ARRAY_FILTER_USE_KEY) + array_filter(getenv(), is_string(...), ARRAY_FILTER_USE_KEY) as $name => $value) {
            if (strtoupper($name) === 'PATH') {
                $path = $path === '' ? $value : $path;
                continue;
            }
            $environment[$name] = $value;
        }
        $directory = self::gitDirectory();

        return $environment + ['PATH' => $directory === null ? $path : $directory . PATH_SEPARATOR . $path];
    }
}
