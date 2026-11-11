<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Ustala listę zmienionych plików i bazę porównania dla hooka `pre-commit` (#40, „Tryb commita i baza porównania”).
 *
 * Pierwszy pasujący stan decyduje, każda porażka ustalenia to `unresolved` (pełny zestaw): błąd gita albo niezaufany diff,
 * operacja w toku (merge, cherry-pick, revert, rebase), repozytorium płytkie, nieznany format obiektów. Pierwszy commit
 * (brak `HEAD`) porównuje z pustym drzewem. Zwykły commit potwierdzony: `HEAD` → indeks. Amend i tryb nieznany: suma ścieżek
 * z `HEAD^` → indeks (puste drzewo dla korzenia) i z `HEAD` → indeks, żeby błędne wykrycie trybu mogło tylko dodać bramki.
 * Gdy którekolwiek z porównań jest puste (indeks równy `HEAD` albo `HEAD^`), lista jest pusta: pusta lista w trybie
 * rzeczywistym to pełny zestaw, więc wykryty tryb nie może dać węższego.
 */
final readonly class ScopeResolver
{
    private const array OPERATIONS = ['MERGE_HEAD', 'CHERRY_PICK_HEAD', 'REVERT_HEAD', 'rebase-merge', 'rebase-apply'];

    private const array EMPTY_TREES = [
        'sha1' => '4b825dc642cb6eb9a060e54bf8d69288fbee4904',
        'sha256' => '6ef19b41225c5369f1c104d45d8d85efa9b057b53b14b4b9b939dd74decc5321',
    ];

    public function __construct(
        private GitRunner $git,
        private DiffParser $parser = new DiffParser(),
    ) {}

    public function staged(CommitMode $mode): ScopeInput
    {
        try {
            return $this->resolveStaged($mode);
        } catch (ScopeException $exception) {
            return $this->unresolved($exception);
        }
    }

    /**
     * Lista zmian `<baza>` → `HEAD` (CI, informacyjnie). Rewizji nie da się rozwiązać to `unresolved`.
     */
    public function range(string $base): ScopeInput
    {
        try {
            if ($base === '' || str_starts_with($base, '-')) {
                throw new ScopeException('Niepoprawna rewizja bazy.');
            }
            $parsed = $this->parser->parse($this->text(['diff', '--raw', '-z', '--no-renames', '--ignore-submodules=none', '--no-ext-diff', $base, 'HEAD', '--']));

            return new ScopeInput($parsed->paths, $parsed->specialModes, sprintf('zakres %s..HEAD', $base));
        } catch (ScopeException $exception) {
            return $this->unresolved($exception);
        }
    }

    private function unresolved(ScopeException $exception): ScopeInput
    {
        return new ScopeInput([], false, 'brak', $exception->getMessage());
    }

    /**
     * @throws ScopeException
     */
    private function resolveStaged(CommitMode $mode): ScopeInput
    {
        $emptyTree = $this->assertSettled();
        $parents = $this->head();
        if ($parents === null) {
            return $this->diffAgainst([$emptyTree], 'pierwszy commit, baza: puste drzewo');
        }
        if ($mode === CommitMode::Plain) {
            return $this->diffAgainst(['HEAD'], 'zwykły commit, baza: HEAD');
        }

        return $this->diffAgainst(['HEAD', $parents[0] ?? $emptyTree], sprintf('tryb %s, baza: HEAD i HEAD^ (suma)', $mode->value));
    }

    /**
     * Sprawdza, że repozytorium jest w stanie, któremu wolno ufać (jedno wywołanie gita), i zwraca OID pustego drzewa.
     *
     * @throws ScopeException
     */
    private function assertSettled(): string
    {
        $lines = array_map('trim', explode("\n", trim($this->text(['rev-parse', '--absolute-git-dir', '--is-shallow-repository', '--show-object-format']))));
        if (count($lines) !== 3 || $lines[0] === '') {
            throw new ScopeException('Nie da się ustalić stanu repozytorium (katalog gita, płytkość, format obiektów).');
        }
        [$gitDir, $shallow, $format] = $lines;
        foreach (self::OPERATIONS as $name) {
            if (file_exists($gitDir . '/' . $name)) {
                throw new ScopeException(sprintf('Operacja gita w toku (%s): pełny zestaw bramek.', $name));
            }
        }
        if ($shallow !== 'false') {
            throw new ScopeException('Repozytorium płytkie (shallow) albo nieznany stan: HEAD^ nie jest wiarygodny.');
        }
        if (!isset(self::EMPTY_TREES[$format])) {
            throw new ScopeException(sprintf('Nieznany format obiektów gita: %s.', $format));
        }

        return self::EMPTY_TREES[$format];
    }

    /**
     * Rodzice `HEAD` albo `null`, gdy `HEAD` jeszcze nie istnieje (pierwszy commit). Każda inna niepewność to wyjątek.
     *
     * @return list<string>|null
     *
     * @throws ScopeException
     */
    private function head(): ?array
    {
        $list = $this->git->run(['rev-list', '--parents', '-n', '1', 'HEAD']);
        if ($list->code !== 0) {
            if ($this->isUnborn()) {
                return null;
            }
            throw new ScopeException('Nie da się rozwiązać HEAD.');
        }
        $tokens = preg_split('~\s+~', trim($list->stdout));
        if ($tokens === false || $tokens[0] === '') {
            throw new ScopeException('Nie da się odczytać rodziców HEAD.');
        }

        return array_slice($tokens, 1);
    }

    /**
     * @throws ScopeException
     */
    private function isUnborn(): bool
    {
        $symbolic = $this->git->run(['symbolic-ref', '-q', 'HEAD']);
        $reference = trim($symbolic->stdout);
        if ($symbolic->code !== 0 || $reference === '') {
            return false;
        }

        return $this->git->run(['show-ref', '--verify', '-q', $reference])->code === 1;
    }

    /**
     * @param list<string> $bases
     *
     * @throws ScopeException
     */
    private function diffAgainst(array $bases, string $description): ScopeInput
    {
        $paths = [];
        $special = false;
        $empty = false;
        foreach ($bases as $base) {
            $parsed = $this->parser->parse($this->text(['diff', '--cached', '--raw', '-z', '--no-renames', '--ignore-submodules=none', '--no-ext-diff', $base, '--']));
            $paths = [...$paths, ...$parsed->paths];
            $special = $special || $parsed->specialModes;
            $empty = $empty || $parsed->paths === [];
        }

        return new ScopeInput($empty ? [] : array_values(array_unique($paths)), $special, $description);
    }

    /**
     * @param list<string> $arguments
     *
     * @throws ScopeException
     */
    private function text(array $arguments): string
    {
        $output = $this->git->run($arguments);
        if ($output->code !== 0) {
            throw new ScopeException(sprintf('git %s zakończył się kodem %d.', $arguments[0], $output->code));
        }

        return $output->stdout;
    }
}
