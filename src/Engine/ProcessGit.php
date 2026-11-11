<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

use CityCouriers\Pc\Scope\CommitMode;
use CityCouriers\Pc\Scope\ProcessGitRunner;
use CityCouriers\Pc\Scope\ScopeResolver;

/**
 * Git przez proces potomny (`git -C <katalog>`), bez powłoki.
 */
final readonly class ProcessGit implements Git
{
    public function __construct(private string $directory) {}

    public function isRepository(): bool
    {
        [$code, $output] = $this->git(['rev-parse', '--is-inside-work-tree']);

        return $code === 0 && trim($output) === 'true';
    }

    public function stagedFiles(): array
    {
        [$code, $output] = $this->git(['diff', '--cached', '--name-only', '--diff-filter=ACMR', '-z']);
        if ($code !== 0) {
            throw new EngineException('Nie da się odczytać plików ze stage\'a (git diff --cached zakończył się błędem).');
        }

        return array_values(array_filter(explode("\0", $output), static fn(string $path): bool => $path !== ''));
    }

    public function scopePaths(): ?array
    {
        $input = (new ScopeResolver(new ProcessGitRunner($this->directory)))->staged(CommitMode::Unknown);
        if ($input->unresolvedReason !== null || $input->specialModes || $input->paths === []) {
            return null;
        }

        return $input->paths;
    }

    public function resolveCommit(string $revision): ?string
    {
        if ($revision === '' || str_starts_with($revision, '-')) {
            return null;
        }

        [$code, $output] = $this->git(['rev-parse', '--verify', '--quiet', $revision . '^{commit}']);
        $oid = trim($output);

        return $code === 0 && preg_match('~^[0-9a-f]{40,64}$~', $oid) === 1 ? $oid : null;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string}
     */
    private function git(array $arguments): array
    {
        $process = proc_open(['git', '-C', $this->directory, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if ($process === false) {
            throw new EngineException('Nie udało się uruchomić gita.');
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }
}
