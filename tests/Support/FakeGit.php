<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Tests\Support;

use CityCouriers\Pc\Engine\EngineException;
use CityCouriers\Pc\Engine\Git;

/**
 * Atrapa gita: repozytorium, lista plików ze stage'a i znane rewizje (nazwa => OID).
 */
final class FakeGit implements Git
{
    /**
     * @param list<string>          $staged
     * @param array<string, string> $revisions
     * @param list<string>|null     $scopePaths
     */
    public function __construct(
        private readonly bool $repository = true,
        private readonly array $staged = [],
        private readonly array $revisions = [],
        private readonly bool $stageBroken = false,
        private readonly ?array $scopePaths = null,
    ) {}

    public function isRepository(): bool
    {
        return $this->repository;
    }

    public function stagedFiles(): array
    {
        if ($this->stageBroken) {
            throw new EngineException('Nie da się odczytać plików ze stage\'a.');
        }

        return $this->staged;
    }

    public function scopePaths(): ?array
    {
        return $this->scopePaths;
    }

    public function resolveCommit(string $revision): ?string
    {
        return $this->revisions[$revision] ?? null;
    }
}
