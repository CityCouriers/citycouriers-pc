<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Engine;

/**
 * Buduje kontekst biegu: zakres, pliki ze stage'a i baza diffu (`--base`) rozwiązana raz do pełnego OID.
 */
final readonly class ContextFactory
{
    public function __construct(
        private Git $git,
        private string $root,
    ) {}

    /**
     * @param array<mixed> $config
     *
     * @throws UsageException
     * @throws EngineException
     */
    public function create(RunOptions $options, array $config): GateContext
    {
        $staged = $options->scope === RunScope::Staged;
        if ($staged && !$this->git->isRepository()) {
            throw new UsageException('Opcja --staged działa tylko w repozytorium git.');
        }

        $diffBase = $options->base === null ? null : $this->resolveBase($options->base);
        $files = $staged ? $this->git->stagedFiles() : null;

        // Pusty stage nie oznacza „brak bramek”: bramki biegną w pełnym zakresie (zasada 4, docs/bramki.md).
        return new GateContext($options->scope, $files === [] ? null : $files, $diffBase, $this->root, $config);
    }

    /**
     * @throws UsageException
     */
    private function resolveBase(string $revision): string
    {
        $oid = $this->git->isRepository() ? $this->git->resolveCommit($revision) : null;
        if ($oid === null) {
            throw new UsageException(
                sprintf('Nie da się rozwiązać rewizji --base: %s.', $revision),
                'invalid_base',
                'Pobierz pełną historię (np. fetch-depth: 0 w actions/checkout) i podaj istniejącą rewizję, np. wynik git merge-base.',
            );
        }

        return $oid;
    }
}
