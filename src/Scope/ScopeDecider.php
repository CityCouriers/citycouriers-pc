<?php

declare(strict_types=1);

namespace CityCouriers\Pc\Scope;

/**
 * Składa całą decyzję: lista zmian z gita (tryb commita albo zakres od bazy), klasyfikacja i polityka egzekwowania.
 * Niczego nie wypisuje.
 */
final readonly class ScopeDecider
{
    /**
     * @param array<string, string> $environment
     */
    public function __construct(
        private ScopeResolver $resolver,
        private CommandLineSource $commandLines = new ProcessCommandLine(),
        private array $environment = [],
    ) {}

    public function decide(RuleSet $rules, Profile $profile, ?string $base, ?int $parentPid): ScopeDecision
    {
        $input = $base !== null ? $this->resolver->range($base) : $this->resolver->staged($this->mode($parentPid));
        $classification = $this->classify(new Classifier($rules), $input);

        return new ScopeDecision((new EnforcementPolicy())->select($classification, $profile, $rules->gates), $input);
    }

    private function mode(?int $parentPid): CommitMode
    {
        return $parentPid === null ? CommitMode::Unknown : (new CommitModeDetector())->detect($this->commandLines->argv($parentPid));
    }

    private function classify(Classifier $classifier, ScopeInput $input): Classification
    {
        if ($input->unresolvedReason !== null) {
            return $classifier->forced(FullCause::Unresolved, $input->unresolvedReason);
        }
        if (($this->environment['PC_HOOK_SCOPE'] ?? '') === 'all') {
            return $classifier->forced(FullCause::ForcedAll, 'PC_HOOK_SCOPE=all');
        }

        return $classifier->classify(new ParsedDiff($input->paths, $input->specialModes));
    }
}
