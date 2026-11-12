<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

use CityCouriers\Pc\CommandResult;

/**
 * Polecenie `pc scope`: które bramki uruchomić dla zmienionych plików (hook `pre-commit` i CI). Na stdout trafiają
 * nazwy bramek rozdzielone spacją, wyjaśnienia na stderr. Kody: 0 (decyzja), 1 (zestawu reguł nie da się wczytać),
 * 2 (błąd użycia). Wywołujący traktuje kod ≠ 0 jako awarię klasyfikatora i uruchamia pełny zestaw bramek.
 */
final readonly class ScopeCommand
{
    public const string DEFAULT_RULES = 'scripts/quality/scope-rules.json';

    public function __construct(
        private ScopeDecider $decider,
        private RuleSetLoader $loader = new RuleSetLoader(),
    ) {}

    /**
     * @param list<string> $arguments argumenty po nazwie polecenia
     */
    public function execute(array $arguments): CommandResult
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (preg_match('~^--(profile|rules|parent-pid|base)=(.+)$~D', $argument, $match) !== 1 || isset($options[$match[1]])) {
                return new CommandResult(2, '', sprintf("Nieznana opcja, powtórzenie albo brak wartości: %s\nUżycie: pc scope [--profile=local-narrow|enforce-all] [--rules=<plik>] [--parent-pid=<pid>] [--base=<rewizja>]\n", $argument));
            }
            $options[$match[1]] = $match[2];
        }

        try {
            $rules = $this->loader->load($options['rules'] ?? self::DEFAULT_RULES);
        } catch (ScopeException $exception) {
            return new CommandResult(1, '', '[scope] BŁĄD: ' . $exception->getMessage() . "\n");
        }

        return $this->decide($rules, $options);
    }

    /**
     * @param array<string, string> $options
     */
    private function decide(RuleSet $rules, array $options): CommandResult
    {
        $resolver = new ProfileResolver();
        $given = $options['profile'] ?? null;
        $notes = $given !== null && !$resolver->isKnown($given) ? [sprintf('[scope] nieznany profil „%s”: stosuję enforce-all', $given)] : [];
        $pid = isset($options['parent-pid']) && ctype_digit($options['parent-pid']) ? (int) $options['parent-pid'] : null;
        $decision = $this->decider->decide($rules, $resolver->resolve($given), $options['base'] ?? null, $pid);
        $lines = [...$notes, ...(new SelectionRenderer())->render($decision->selection, $decision->input)];

        return new CommandResult(0, implode(' ', $decision->selection->gates) . "\n", implode("\n", $lines) . "\n");
    }
}
